<?php
declare(strict_types=1);

namespace App\Queue;

interface JobHandler
{
    /**
     * Process the job. MUST throw on transient failure (so the queue
     * retries with backoff). Return normally on success.
     *
     * @param array<string,mixed> $payload  the JSON-decoded payload from the row
     * @param array<string,mixed> $meta     {id, attempts, max_attempts}
     */
    public function handle(array $payload, array $meta): void;
}
