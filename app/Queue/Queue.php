<?php
declare(strict_types=1);

namespace App\Queue;

use App\Config\Database;
use RuntimeException;

/**
 * Queue
 * -----
 * Tiny DB-backed job queue. No Redis / RabbitMQ — just a single InnoDB table
 * with row-level claim semantics, suitable up to ~thousands of msg/min.
 *
 *  - enqueue($type, $payload, $delay=0, $maxAttempts=5)
 *  - claim($batch=10, $workerId=null) — atomically marks N pending jobs as
 *    "processing" and returns them to the worker
 *  - markSent($id), markFailed($id, $error, $delaySeconds)
 *  - stats(), gc()
 *
 * The atomic claim works as:
 *   1) UPDATE  SET status='processing', locked_by=:wid
 *      WHERE   status='pending' AND available_at<=:now
 *      ORDER BY available_at LIMIT :n
 *   2) SELECT  WHERE locked_by=:wid AND status='processing'
 *
 * Two workers can never claim the same job because step 1 is one
 * indivisible UPDATE statement.
 */
final class Queue
{
    /** Exponential-backoff schedule (seconds) — index = attempt number. */
    private const BACKOFF_SECONDS = [30, 60, 120, 300, 600, 1800];

    /**
     * Enqueue a new job.
     *
     * @param array<string,mixed> $payload
     * @param int $delaySeconds Delay before the job becomes claimable
     */
    public static function enqueue(
        string $type,
        array $payload,
        int $delaySeconds = 0,
        int $maxAttempts = 5
    ): int {
        $availableAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
        Database::execute(
            "INSERT INTO notification_jobs
                (job_type, payload, status, max_attempts, available_at)
             VALUES (:t, :p, 'pending', :ma, :av)",
            [
                ':t'  => $type,
                ':p'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ':ma' => max(1, min(20, $maxAttempts)),
                ':av' => $availableAt,
            ]
        );
        return (int) Database::master()->lastInsertId();
    }

    /**
     * Atomically claims up to $batch pending jobs.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function claim(int $batch = 10, ?string $workerId = null): array
    {
        $workerId = $workerId ?? self::generateWorkerId();
        $now      = date('Y-m-d H:i:s');
        $batch    = max(1, min(100, $batch));

        // Step 1: claim pending jobs by setting locked_by.
        // We use ORDER BY available_at to get FIFO behavior.
        // The IN-subquery-of-subquery pattern works on both MySQL and SQLite
        // (MySQL forbids self-referencing subqueries unless wrapped, SQLite
        // doesn't support UPDATE ... ORDER BY ... LIMIT directly).
        $sql = "UPDATE notification_jobs
                   SET status      = 'processing',
                       locked_at   = :now,
                       locked_by   = :wid,
                       attempts    = attempts + 1,
                       updated_at  = :now4
                 WHERE id IN (
                   SELECT id FROM (
                     SELECT id FROM notification_jobs
                      WHERE status = 'pending'
                        AND available_at <= :now2
                      ORDER BY available_at ASC
                      LIMIT $batch
                   ) AS x
                 )";
        Database::execute($sql, [
            ':now'  => $now,
            ':now2' => $now,
            ':now4' => $now,
            ':wid'  => $workerId,
        ]);

        // Step 2: read back what we claimed.
        $rows = Database::select(
            "SELECT id, job_type, payload, attempts, max_attempts, locked_at
             FROM notification_jobs
             WHERE locked_by = :wid AND status = 'processing'
             ORDER BY id ASC",
            [':wid' => $workerId]
        );
        foreach ($rows as &$r) {
            $r['payload'] = $r['payload'] ? json_decode((string) $r['payload'], true) : [];
            if (!is_array($r['payload'])) $r['payload'] = [];
        }
        unset($r);
        return $rows;
    }

    public static function markSent(int $id): void
    {
        Database::execute(
            "UPDATE notification_jobs
                SET status = 'sent', last_error = NULL, locked_by = NULL, locked_at = NULL
              WHERE id = :id",
            [':id' => $id]
        );
    }

    /**
     * Mark a job as failed for this attempt.
     * If attempts < max_attempts, schedule a retry with exponential backoff.
     * Otherwise, mark final status as 'failed'.
     */
    public static function markFailed(int $id, string $error): void
    {
        $row = Database::selectOne(
            "SELECT attempts, max_attempts FROM notification_jobs WHERE id = :id",
            [':id' => $id]
        );
        if ($row === null) return;

        $attempts    = (int) $row['attempts'];
        $maxAttempts = (int) $row['max_attempts'];

        if ($attempts >= $maxAttempts) {
            // Final failure
            Database::execute(
                "UPDATE notification_jobs
                    SET status     = 'failed',
                        last_error = :e,
                        locked_by  = NULL,
                        locked_at  = NULL
                  WHERE id = :id",
                [':e' => mb_substr($error, 0, 4000), ':id' => $id]
            );
            return;
        }

        // Schedule retry
        $delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];
        $availableAt = date('Y-m-d H:i:s', time() + $delay);

        Database::execute(
            "UPDATE notification_jobs
                SET status       = 'pending',
                    locked_by    = NULL,
                    locked_at    = NULL,
                    available_at = :av,
                    last_error   = :e
              WHERE id = :id",
            [':av' => $availableAt, ':e' => mb_substr($error, 0, 4000), ':id' => $id]
        );
    }

    /**
     * Recover jobs that have been "processing" for too long
     * (e.g. worker crashed). Returns count rescheduled.
     */
    public static function reclaimStale(int $stuckSeconds = 300): int
    {
        $threshold = date('Y-m-d H:i:s', time() - $stuckSeconds);
        return Database::execute(
            "UPDATE notification_jobs
                SET status     = 'pending',
                    locked_at  = NULL,
                    locked_by  = NULL,
                    last_error = '[reclaimed: worker crashed or timed out]'
              WHERE status   = 'processing'
                AND locked_at IS NOT NULL
                AND locked_at < :t",
            [':t' => $threshold]
        );
    }

    /**
     * @return array<string,int|string|null>
     */
    public static function stats(): array
    {
        $row = Database::selectOne(
            "SELECT
                SUM(status = 'pending')    AS pending,
                SUM(status = 'processing') AS processing,
                SUM(status = 'sent')       AS sent,
                SUM(status = 'failed')     AS failed,
                MIN(CASE WHEN status = 'pending' THEN available_at END) AS oldest_pending
             FROM notification_jobs"
        );
        // Sent in last 24h
        $sent24Cutoff = date('Y-m-d H:i:s', time() - 86400);
        $sent24Row = Database::selectOne(
            "SELECT COUNT(*) c FROM notification_jobs
              WHERE status = 'sent' AND updated_at >= :t",
            [':t' => $sent24Cutoff]
        );
        return [
            'pending'        => (int) ($row['pending']    ?? 0),
            'processing'     => (int) ($row['processing'] ?? 0),
            'sent'           => (int) ($row['sent']       ?? 0),
            'failed'         => (int) ($row['failed']     ?? 0),
            'sent_24h'       => (int) ($sent24Row['c']    ?? 0),
            'oldest_pending' => $row['oldest_pending'] ?? null,
        ];
    }

    /** Deletes finished jobs older than $days. Returns count removed. */
    public static function gc(int $days = 7): int
    {
        $threshold = date('Y-m-d H:i:s', time() - $days * 86400);
        return Database::execute(
            "DELETE FROM notification_jobs
              WHERE status IN ('sent', 'failed')
                AND updated_at < :t",
            [':t' => $threshold]
        );
    }

    private static function generateWorkerId(): string
    {
        return (gethostname() ?: 'worker') . '.' . getmypid()
             . '.' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    /**
     * Resolves a JobHandler implementation for the given job type.
     */
    public static function handlerFor(string $type): JobHandler
    {
        return match ($type) {
            'tg_send' => new \App\Queue\Handlers\TelegramSendHandler(),
            default   => throw new RuntimeException("Unknown job type: $type"),
        };
    }
}
