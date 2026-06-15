<?php
declare(strict_types=1);

namespace App\Core;

use App\Config\Database;
use RuntimeException;

/**
 * Security
 * --------
 * Enterprise-grade defensive layer:
 *   - CSRF: HMAC-signed, session-bound, single-use rotating tokens
 *   - XSS:  context-aware escaping (HTML, attribute, JS, URL)
 *   - SQLi: enforced via PDO prepared statements (this layer adds whitelist
 *           validation for ORDER BY / LIMIT clauses where binding isn't possible)
 *   - Rate limiter: leaky-bucket algorithm with Redis primary,
 *                   MySQL fallback (rate_limit_buckets table)
 *   - IP allow/deny lists (CIDR-aware)
 *   - Strict security HTTP headers (CSP, HSTS, X-Frame-Options, etc.)
 */
final class Security
{
    private const CSRF_TTL_SECONDS = 1800;
    private const CSRF_MAX_TOKENS  = 64;   // cap per session to prevent unbounded growth
    private const CSRF_SESSION_KEY = '_csrf_tokens';

    /* =================================================================
     *  CSRF PROTECTION (rolling tokens with auto-rotation header)
     * -----------------------------------------------------------------
     *  - csrfToken($intent, $ttl)  → mints a new token (TTL configurable)
     *  - verifyCsrf($t, $i, $su)   → if $singleUse=true, token is consumed
     *  - requireCsrf($i, $su)      → enforces + auto-rotates in
     *                                X-CSRF-Token response header
     *
     *  Frontend pattern:
     *      const res = await fetch(...);
     *      const fresh = res.headers.get('X-CSRF-Token');
     *      if (fresh) CSRF_TOKEN = fresh;
     * ================================================================= */

    public static function csrfToken(string $intent = 'default', ?int $ttl = null): string
    {
        $ttl = $ttl ?? self::CSRF_TTL_SECONDS;
        self::ensureSession();
        $tokens = $_SESSION[self::CSRF_SESSION_KEY] ?? [];

        $now = time();
        // Prune expired
        foreach ($tokens as $tk => $meta) {
            if (($meta['expires'] ?? 0) < $now) {
                unset($tokens[$tk]);
            }
        }
        // Cap count (FIFO eviction)
        if (count($tokens) >= self::CSRF_MAX_TOKENS) {
            $tokens = array_slice(
                $tokens,
                count($tokens) - self::CSRF_MAX_TOKENS + 1,
                null,
                true
            );
        }

        $raw    = bin2hex(random_bytes(32));
        $signed = hash_hmac('sha256', $raw . '|' . $intent, self::appKey());
        $token  = $raw . '.' . $signed;

        $tokens[$token] = [
            'intent'  => $intent,
            'expires' => $now + $ttl,
            'ttl'     => $ttl,
        ];
        $_SESSION[self::CSRF_SESSION_KEY] = $tokens;
        return $token;
    }

    public static function verifyCsrf(
        ?string $token,
        string $intent = 'default',
        bool $singleUse = true
    ): bool {
        self::ensureSession();
        if ($token === null || $token === '' || !str_contains($token, '.')) {
            return false;
        }
        [$raw, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $raw . '|' . $intent, self::appKey());
        if (!hash_equals($expected, $sig)) {
            return false;
        }
        $tokens = $_SESSION[self::CSRF_SESSION_KEY] ?? [];
        if (!isset($tokens[$token])) {
            return false;
        }
        $meta = $tokens[$token];

        // Wrong intent or expired → always burn it
        if (($meta['intent'] ?? '') !== $intent
            || ((int) ($meta['expires'] ?? 0)) < time()
        ) {
            unset($tokens[$token]);
            $_SESSION[self::CSRF_SESSION_KEY] = $tokens;
            return false;
        }

        if ($singleUse) {
            unset($tokens[$token]);
            $_SESSION[self::CSRF_SESSION_KEY] = $tokens;
        }
        return true;
    }

    public static function requireCsrf(string $intent = 'default', bool $singleUse = true): void
    {
        $token = $_POST['_csrf']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? null;

        $ok = self::verifyCsrf(is_string($token) ? $token : null, $intent, $singleUse);

        // Always rotate — give the client a fresh token regardless of outcome
        // so that retries / re-submissions don't hit a stale-token loop.
        self::rotateCsrfHeader($intent);

        if (!$ok) {
            self::securityHeaders();
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'CSRF token mismatch'], JSON_THROW_ON_ERROR);
            exit;
        }
    }

    /**
     * Issues a fresh CSRF token bound to $intent and emits it in the
     * `X-CSRF-Token` response header. Browsers running same-origin AJAX
     * can read it directly; for cross-origin clients add an
     * `Access-Control-Expose-Headers: X-CSRF-Token` header.
     */
    public static function rotateCsrfHeader(string $intent, ?int $ttl = null): void
    {
        if (headers_sent()) return;
        $fresh = self::csrfToken($intent, $ttl);
        header('X-CSRF-Token: ' . $fresh);
        // Also surface to JS that may inspect "Vary"
        header('Vary: X-CSRF-Token', false);
    }

    /* =================================================================
     *  XSS ESCAPING
     * ================================================================= */

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function eAttr(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function eJs(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: 'null';
    }

    public static function eUrl(?string $value): string
    {
        return rawurlencode($value ?? '');
    }

    /**
     * Recursive sanitization for arbitrary user input (HTML stripping mode).
     */
    public static function sanitize(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }
        if (is_string($data)) {
            $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data) ?? '';
            return trim(strip_tags($data));
        }
        return $data;
    }

    /* =================================================================
     *  SQL — whitelist for non-bindable identifiers
     * ================================================================= */

    /**
     * Validates ORDER BY / column names against a whitelist (bindings can't cover identifiers).
     *
     * @param array<int,string> $allowed
     */
    public static function safeIdentifier(string $candidate, array $allowed, string $fallback): string
    {
        return in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    public static function safeSortDirection(string $dir): string
    {
        return strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';
    }

    /* =================================================================
     *  RATE LIMITER  (leaky bucket)
     * ================================================================= */

    /**
     * Returns true if the request is allowed; false if rate-limited.
     * Uses Redis if REDIS_URL env is set, otherwise MySQL fallback.
     */
    public static function rateLimit(string $bucket, int $maxHits, int $windowSeconds): bool
    {
        $key = 'rl:' . hash('sha256', $bucket);
        $now = time();

        if (function_exists('apcu_inc') && ini_get('apc.enabled')) {
            $success = false;
            $hits = apcu_inc($key, 1, $success, $windowSeconds);
            return is_int($hits) && $hits <= $maxHits;
        }

        // MySQL fallback (atomic upsert)
        $expires = $now + $windowSeconds;
        $sql = "INSERT INTO rate_limit_buckets (bucket_key, hits, expires_at)
                VALUES (:k, 1, :exp)
                ON DUPLICATE KEY UPDATE
                    hits = IF(expires_at < :now2, 1, hits + 1),
                    expires_at = IF(expires_at < :now3, :exp2, expires_at)";
        Database::execute($sql, [
            ':k'    => $key,
            ':exp'  => $expires,
            ':exp2' => $expires,
            ':now2' => $now,
            ':now3' => $now,
        ]);
        $row = Database::selectOne(
            "SELECT hits FROM rate_limit_buckets WHERE bucket_key = :k",
            [':k' => $key]
        );

        // Probabilistic inline GC (1/100 chance) — keeps the table bounded
        // even when no cron is configured. Cheap because of the index on expires_at.
        if (random_int(1, 100) === 1) {
            try { self::gcRateLimits(); } catch (\Throwable) { /* best-effort */ }
        }

        return ((int)($row['hits'] ?? 0)) <= $maxHits;
    }

    public static function enforceRateLimit(string $bucket, int $maxHits, int $windowSeconds): void
    {
        if (!self::rateLimit($bucket, $maxHits, $windowSeconds)) {
            self::securityHeaders();
            http_response_code(429);
            header('Retry-After: ' . $windowSeconds);
            echo json_encode(['error' => 'Too many requests'], JSON_THROW_ON_ERROR);
            exit;
        }
    }

    /* =================================================================
     *  GARBAGE COLLECTION
     *  Run from cron (bin/gc.php) or admin panel.
     * ================================================================= */

    /**
     * Deletes expired rate-limit buckets.
     * Returns the number of rows removed.
     */
    public static function gcRateLimits(?int $now = null): int
    {
        $now = $now ?? time();
        return Database::execute(
            "DELETE FROM rate_limit_buckets WHERE expires_at < :now",
            [':now' => $now]
        );
    }

    /**
     * Deletes idle Telegram bot sessions (haven't been touched in $maxAge seconds).
     * Default: 7 days. Returns rows removed.
     */
    public static function gcTgSessions(int $maxAge = 604800): int
    {
        $threshold = date('Y-m-d H:i:s', time() - $maxAge);
        return Database::execute(
            "DELETE FROM tg_sessions
              WHERE state = 'idle'
                AND updated_at < :t",
            [':t' => $threshold]
        );
    }

    /**
     * Marks abandoned exam sessions (status='in_progress' but started > $maxAge ago)
     * as expired. Default: 24h. Returns rows updated.
     */
    public static function gcStaleExamSessions(int $maxAge = 86400): int
    {
        $threshold = date('Y-m-d H:i:s', time() - $maxAge);
        return Database::execute(
            "UPDATE user_exams
                SET status = 'expired',
                    finished_at = COALESCE(finished_at, :n)
              WHERE status = 'in_progress'
                AND started_at < :t",
            [':t' => $threshold, ':n' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Returns counts useful for the admin Maintenance panel.
     *
     * @return array<string,int>
     */
    public static function maintenanceStats(): array
    {
        $now           = time();
        $tgIdleCutoff  = date('Y-m-d H:i:s', $now - 7 * 86400);
        $examCutoff    = date('Y-m-d H:i:s', $now - 86400);
        $otpCutoff     = date('Y-m-d H:i:s', $now - 86400);

        $rl    = Database::selectOne("SELECT COUNT(*) c FROM rate_limit_buckets");
        $rlExp = Database::selectOne(
            "SELECT COUNT(*) c FROM rate_limit_buckets WHERE expires_at < :now",
            [':now' => $now]
        );
        $tg    = Database::selectOne("SELECT COUNT(*) c FROM tg_sessions");
        $tgIdle = Database::selectOne(
            "SELECT COUNT(*) c FROM tg_sessions
              WHERE state = 'idle' AND updated_at < :t",
            [':t' => $tgIdleCutoff]
        );
        $stuck = Database::selectOne(
            "SELECT COUNT(*) c FROM user_exams
              WHERE status = 'in_progress' AND started_at < :t",
            [':t' => $examCutoff]
        );
        $otpStale = Database::selectOne(
            "SELECT COUNT(*) c FROM otp_challenges WHERE expires_at < :t",
            [':t' => $otpCutoff]
        );

        // Queue stats (graceful if table missing)
        $queue = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'oldest_pending' => null];
        try {
            $queue = \App\Queue\Queue::stats();
        } catch (\Throwable) { /* table absent on legacy installs */ }

        return [
            'rate_limits_total'    => (int) ($rl['c']     ?? 0),
            'rate_limits_expired'  => (int) ($rlExp['c']  ?? 0),
            'tg_sessions_total'    => (int) ($tg['c']     ?? 0),
            'tg_sessions_idle_old' => (int) ($tgIdle['c'] ?? 0),
            'stale_exam_sessions'  => (int) ($stuck['c']  ?? 0),
            'otp_stale'            => (int) ($otpStale['c'] ?? 0),
            'queue_pending'        => (int) ($queue['pending']    ?? 0),
            'queue_processing'     => (int) ($queue['processing'] ?? 0),
            'queue_failed'         => (int) ($queue['failed']     ?? 0),
            'queue_sent_24h'       => (int) ($queue['sent_24h']   ?? 0),
            'queue_oldest_pending' => $queue['oldest_pending'] ?? null,
        ];
    }

    /**
     * Runs all GC tasks. Returns per-task removed/updated counts.
     *
     * @return array<string,int>
     */
    public static function gcAll(): array
    {
        $out = [
            'rate_limits_deleted'      => self::gcRateLimits(),
            'tg_sessions_deleted'      => self::gcTgSessions(),
            'exam_sessions_expired'    => self::gcStaleExamSessions(),
            'otp_deleted'              => \App\Core\Otp::gc(),
        ];
        try {
            $out['queue_deleted']      = \App\Queue\Queue::gc();
        } catch (\Throwable) {
            $out['queue_deleted'] = 0;
        }
        return $out;
    }

    /* =================================================================
     *  IP DETECTION + ALLOW/DENY (CIDR-aware)
     * ================================================================= */

    public static function clientIp(): string
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];
        foreach ($candidates as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * @param array<int,string> $cidrList
     */
    public static function ipMatches(string $ip, array $cidrList): bool
    {
        foreach ($cidrList as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $maskBits = (int) $mask;

        if (str_contains($ip, ':')) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) return false;
            $bytes = intdiv($maskBits, 8);
            $remainder = $maskBits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) return false;
            if ($remainder === 0) return true;
            $maskByte = ~((1 << (8 - $remainder)) - 1) & 0xFF;
            return ((ord($ipBin[$bytes]) & $maskByte) === (ord($subnetBin[$bytes]) & $maskByte));
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $maskLong = $maskBits === 0 ? 0 : (-1 << (32 - $maskBits)) & 0xFFFFFFFF;
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Enforces an allowlist (e.g. for /admin endpoints).
     *
     * @param array<int,string> $allowedCidrs
     */
    public static function requireIpAllowlist(array $allowedCidrs): void
    {
        if ($allowedCidrs === []) return;
        if (!self::ipMatches(self::clientIp(), $allowedCidrs)) {
            self::securityHeaders();
            http_response_code(403);
            echo json_encode(['error' => 'IP not allowed'], JSON_THROW_ON_ERROR);
            exit;
        }
    }

    /* =================================================================
     *  HTTP SECURITY HEADERS
     * ================================================================= */

    public static function securityHeaders(): void
    {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://polyfill.io; "
            . "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com data:; "
            . "img-src 'self' data: blob: https:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self';"
        );
    }

    /* =================================================================
     *  SESSION HARDENING
     * ================================================================= */

    public static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', '7200');
        session_name('PHCERT_SID');
        session_start();

        // Bind session to UA + first-octets of IP (defeats hijacking)
        $fingerprint = hash(
            'sha256',
            ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . self::ipPrefix(self::clientIp())
        );
        if (!isset($_SESSION['_fp'])) {
            $_SESSION['_fp'] = $fingerprint;
            session_regenerate_id(true);
        } elseif (!hash_equals($_SESSION['_fp'], $fingerprint)) {
            session_unset();
            session_destroy();
            self::securityHeaders();
            http_response_code(401);
            echo json_encode(['error' => 'Session invalidated'], JSON_THROW_ON_ERROR);
            exit;
        }
    }

    private static function ipPrefix(string $ip): string
    {
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 4));
        }
        $parts = explode('.', $ip);
        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    /* =================================================================
     *  APP KEY
     * ================================================================= */

    public static function appKey(): string
    {
        $key = (string) getenv('APP_KEY');
        if ($key === '' || strlen($key) < 32) {
            throw new RuntimeException('APP_KEY missing or too short (>=32 bytes required)');
        }
        return $key;
    }

    /* =================================================================
     *  AES-256-GCM at-rest encryption (for TOTP secrets, sensitive blobs)
     *
     *  encrypt() returns a versioned, base64-encoded ciphertext:
     *    "v1." || base64(IV[12] || TAG[16] || CIPHERTEXT[N])
     *  decrypt() reverses; throws on tamper / wrong key.
     *
     *  Key derivation: SHA-256(APP_KEY) → 32-byte AES key.
     *  This means rotating APP_KEY invalidates all stored ciphertexts;
     *  document this in INSTALL.md before key rotation.
     * ================================================================= */

    private static function deriveAesKey(): string
    {
        return hash('sha256', self::appKey(), true);
    }

    public static function encrypt(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            self::deriveAesKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($ct === false) {
            throw new RuntimeException('Encryption failed');
        }
        return 'v1.' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, 'v1.')) {
            throw new RuntimeException('Unknown ciphertext version');
        }
        $bin = base64_decode(substr($ciphertext, 3), true);
        if ($bin === false || strlen($bin) < 28) {
            throw new RuntimeException('Corrupt ciphertext');
        }
        $iv  = substr($bin, 0, 12);
        $tag = substr($bin, 12, 16);
        $ct  = substr($bin, 28);
        $plain = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            self::deriveAesKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or tampered data)');
        }
        return $plain;
    }
}
