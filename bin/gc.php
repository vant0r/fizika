<?php
declare(strict_types=1);

/**
 * bin/gc.php — Garbage Collection cron script
 * --------------------------------------------
 * Runs the same maintenance tasks the admin panel exposes.
 * Designed to be invoked from cron — e.g. every 5 minutes:
 *
 *   crontab -e
 *   ----------------------------------------------------
 *   #  m   h dom mon dow   command
 *      <every-5-min> *  *   *   *    /usr/bin/php /var/www/physics-cert/bin/gc.php >>/var/log/physics-cert-gc.log 2>&1
 *   ----------------------------------------------------
 *   (replace <every-5-min> with the literal "* /5" without the space)
 *
 * Exit codes:
 *    0 — success
 *    1 — bootstrap or DB failure
 *
 * The script is idempotent and safe to invoke concurrently
 * (each task uses an atomic SQL DELETE/UPDATE).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Security;

$start = microtime(true);

try {
    $before = Security::maintenanceStats();
    $cleaned = Security::gcAll();
    $after  = Security::maintenanceStats();

    $elapsed = number_format((microtime(true) - $start) * 1000, 1);
    $stamp   = date('Y-m-d H:i:s');

    $line = sprintf(
        "[%s] gc.php OK  ─  rl_deleted=%d  tg_deleted=%d  exam_expired=%d  "
        . "rl_remaining=%d  tg_remaining=%d  stuck_remaining=%d  (%sms)",
        $stamp,
        $cleaned['rate_limits_deleted'],
        $cleaned['tg_sessions_deleted'],
        $cleaned['exam_sessions_expired'],
        $after['rate_limits_total'],
        $after['tg_sessions_total'],
        $after['stale_exam_sessions'],
        $elapsed
    );
    fwrite(STDOUT, $line . PHP_EOL);
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] gc.php FAIL ─  %s%s%s",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        PHP_EOL,
        $e->getTraceAsString() . PHP_EOL
    ));
    exit(1);
}
