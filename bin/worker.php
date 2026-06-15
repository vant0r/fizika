<?php
declare(strict_types=1);

/**
 * bin/worker.php — Notification queue worker
 *
 * Modes:
 *   --once       run a single batch and exit (good for cron every minute)
 *   (default)    run forever (good under systemd or supervisord)
 *
 * Tunables:
 *   --batch=N    claim up to N jobs per cycle (default 10)
 *   --sleep=N    seconds to sleep between cycles when empty (default 1)
 *   --max=N      exit after processing N jobs total (default 0 = unlimited)
 *
 * Recommended cron (one-shot mode, picks up backlog every minute):
 *   * * * * *  /usr/bin/php /var/www/physics-cert/bin/worker.php --once \
 *              >>/var/log/physics-cert-worker.log 2>&1
 *
 * Recommended systemd:
 *   ExecStart=/usr/bin/php /var/www/physics-cert/bin/worker.php
 *   Restart=always
 *   RestartSec=5
 *
 * SIGTERM/SIGINT are honoured: the worker finishes the current job
 * before exiting cleanly.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Queue\Queue;

/* ------------------ argv parsing ------------------ */
$opts = [
    'once'  => false,
    'batch' => 10,
    'sleep' => 1,
    'max'   => 0,
];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--once') { $opts['once'] = true; continue; }
    if (preg_match('/^--(batch|sleep|max)=(\d+)$/', $arg, $m)) {
        $opts[$m[1]] = (int) $m[2];
        continue;
    }
}

/* ------------------ signal handling ------------------ */
$shutdown = false;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$shutdown) { $shutdown = true; });
    pcntl_signal(SIGINT,  function () use (&$shutdown) { $shutdown = true; });
}

$workerId = (gethostname() ?: 'worker') . '.' . getmypid();
$processed = 0;
$start = microtime(true);

logLine("worker starting id=$workerId once=" . ($opts['once'] ? 'yes' : 'no')
    . " batch={$opts['batch']} sleep={$opts['sleep']}");

/* ------------------ main loop ------------------ */
while (!$shutdown) {
    // Reclaim jobs stuck "processing" for >5 min (orphaned by crashes)
    try { Queue::reclaimStale(300); } catch (\Throwable $e) {
        logLine("reclaimStale error: " . $e->getMessage());
    }

    try {
        $jobs = Queue::claim($opts['batch'], $workerId);
    } catch (\Throwable $e) {
        logLine("claim error: " . $e->getMessage());
        sleep((int) max(1, $opts['sleep']));
        continue;
    }

    if ($jobs === []) {
        if ($opts['once']) break;
        sleep((int) max(1, $opts['sleep']));
        continue;
    }

    foreach ($jobs as $job) {
        $id = (int) $job['id'];
        $type = (string) $job['job_type'];
        $payload = is_array($job['payload']) ? $job['payload'] : [];

        try {
            $handler = Queue::handlerFor($type);
            $handler->handle($payload, [
                'id'           => $id,
                'attempts'     => (int) $job['attempts'],
                'max_attempts' => (int) $job['max_attempts'],
            ]);
            Queue::markSent($id);
            logLine("job=$id type=$type ok");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Queue::markFailed($id, $msg);
            logLine("job=$id type=$type fail attempt={$job['attempts']}/{$job['max_attempts']} err=\"$msg\"");
        }

        $processed++;
        if ($opts['max'] > 0 && $processed >= $opts['max']) {
            logLine("reached --max=$processed, exiting");
            $shutdown = true;
            break;
        }
        if ($shutdown) break;
    }

    if ($opts['once']) break;
}

$elapsed = number_format(microtime(true) - $start, 2);
logLine("worker exiting id=$workerId processed=$processed elapsed={$elapsed}s");
exit(0);

function logLine(string $msg): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}
