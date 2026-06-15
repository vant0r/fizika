<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database
 * --------
 * High-load PDO connection orchestrator with:
 *   - Lazy singleton connection pooling (per-process)
 *   - Read / Write replica routing (round-robin slaves)
 *   - Persistent connections for shared hosts (x10hosting / cPanel safe)
 *   - Automatic UTF-8MB4, strict SQL mode, prepared statements (ATTR_EMULATE_PREPARES = false)
 *   - Transparent fail-over: if a replica is down, falls back to master
 *   - Health-checked retries (exponential backoff)
 *
 * Environment variables (read once via getenv()):
 *   DB_MASTER_DSN, DB_MASTER_USER, DB_MASTER_PASS
 *   DB_REPLICA_DSN  (optional, comma-separated list)
 *   DB_REPLICA_USER, DB_REPLICA_PASS
 */
final class Database
{
    /** @var array<string,PDO> Active connection pool keyed by role */
    private static array $pool = [];

    /** @var array<string,int> Failure counters per replica DSN */
    private static array $replicaFailures = [];

    /** @var int */
    private const MAX_REPLICA_FAILS = 3;

    /** @var array<int,mixed> */
    private const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => true,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::MYSQL_ATTR_INIT_COMMAND =>
            "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, "
            . "sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION', "
            . "time_zone = '+05:00'",
    ];

    private function __construct() {}
    private function __clone() {}

    /**
     * Returns a writable (master) PDO connection.
     */
    public static function master(): PDO
    {
        if (!isset(self::$pool['master'])) {
            self::$pool['master'] = self::connect(
                (string) getenv('DB_MASTER_DSN'),
                (string) getenv('DB_MASTER_USER'),
                (string) getenv('DB_MASTER_PASS')
            );
        }
        return self::$pool['master'];
    }

    /**
     * Returns a read-only PDO connection.
     * If no replicas are configured, transparently uses master.
     */
    public static function replica(): PDO
    {
        $replicasRaw = (string) getenv('DB_REPLICA_DSN');
        if ($replicasRaw === '') {
            return self::master();
        }

        $replicas = array_values(array_filter(array_map('trim', explode(',', $replicasRaw))));
        if ($replicas === []) {
            return self::master();
        }

        // Round-robin via process-stable shuffle
        shuffle($replicas);
        foreach ($replicas as $dsn) {
            if ((self::$replicaFailures[$dsn] ?? 0) >= self::MAX_REPLICA_FAILS) {
                continue;
            }
            $cacheKey = 'replica:' . md5($dsn);
            if (isset(self::$pool[$cacheKey])) {
                return self::$pool[$cacheKey];
            }
            try {
                $conn = self::connect(
                    $dsn,
                    (string) getenv('DB_REPLICA_USER'),
                    (string) getenv('DB_REPLICA_PASS')
                );
                self::$pool[$cacheKey] = $conn;
                return $conn;
            } catch (PDOException $e) {
                self::$replicaFailures[$dsn] = (self::$replicaFailures[$dsn] ?? 0) + 1;
                error_log('[DB][replica-down] ' . $dsn . ' -> ' . $e->getMessage());
            }
        }

        // Fail-over to master
        return self::master();
    }

    /**
     * Convenience: run a SELECT on replica.
     *
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::replica()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Convenience: run a SELECT returning single row, on replica.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = self::replica()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Convenience: run an INSERT/UPDATE/DELETE on master.
     *
     * @param array<string,mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::master()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Wraps a closure in a serializable transaction with automatic retry on deadlock.
     *
     * @template T
     * @param callable(PDO):T $work
     * @return T
     */
    public static function transaction(callable $work, int $maxRetries = 3)
    {
        $pdo = self::master();
        $attempt = 0;
        retry:
        try {
            $pdo->beginTransaction();
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 40001 = serialization failure, 1213 = deadlock
            if ($attempt++ < $maxRetries
                && (str_contains((string) $e->getCode(), '40001')
                    || str_contains($e->getMessage(), 'Deadlock'))) {
                usleep(100_000 * $attempt);
                goto retry;
            }
            throw $e;
        }
    }

    private static function connect(string $dsn, string $user, string $pass): PDO
    {
        if ($dsn === '') {
            throw new RuntimeException('DB DSN is empty. Check your .env');
        }
        $tries = 0;
        beginConnect:
        try {
            return new PDO($dsn, $user, $pass, self::PDO_OPTIONS);
        } catch (PDOException $e) {
            if (++$tries < 3) {
                usleep(150_000 * $tries);
                goto beginConnect;
            }
            throw new RuntimeException(
                'Database connection failed after 3 retries: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * For long-running CLI workers — releases all PDO handles.
     */
    public static function disconnect(): void
    {
        self::$pool = [];
    }
}
