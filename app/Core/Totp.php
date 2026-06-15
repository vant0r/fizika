<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Totp
 * ----
 * Time-based One-Time Password (RFC 6238) — zero-dependency, compatible
 * with Google Authenticator, Authy, 1Password, FreeOTP, Aegis, Yubico OTP, etc.
 *
 *  - generateSecret()             — 20 bytes of crypto RNG, Base32-encoded
 *  - code($secret, $time=null)    — current 6-digit code
 *  - verify($secret, $code, $window=1, $time=null)
 *                                 — true if $code matches current ±$window timesteps
 *  - provisioningUri($secret, $accountName, $issuer)
 *                                 — otpauth:// URI for QR scanning
 *
 * Algorithm: HMAC-SHA1, 30-second period, 6 digits, dynamic-truncation.
 * Counter is encoded as 64-bit big-endian unsigned (pack 'J').
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $base32Secret, ?int $time = null): string
    {
        $time    = $time ?? time();
        $counter = intdiv($time, self::PERIOD);
        return self::hotp($base32Secret, $counter);
    }

    /**
     * Verify a code against ±$window timesteps (default 1 = covers ~90s of clock drift).
     * Uses constant-time comparison.
     */
    public static function verify(
        string $base32Secret,
        string $code,
        int $window = 1,
        ?int $time = null
    ): bool {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) return false;

        $time    = $time ?? time();
        $counter = intdiv($time, self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $expected = self::hotp($base32Secret, $counter + $offset);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    public static function provisioningUri(
        string $base32Secret,
        string $accountName,
        string $issuer = 'Physics Cert'
    ): string {
        $label  = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret'    => $base32Secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /* =========================================================
     *  HOTP (RFC 4226) — used internally by code()/verify()
     * ========================================================= */
    private static function hotp(string $base32Secret, int $counter): string
    {
        $key = self::base32Decode($base32Secret);
        if ($key === '' || $counter < 0) {
            return str_repeat('0', self::DIGITS);
        }

        // 64-bit big-endian unsigned counter
        $bin  = pack('J', $counter);
        $hash = hash_hmac('sha1', $bin, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset    ]) & 0x7F) << 24) |
            ( ord($hash[$offset + 1])         << 16) |
            ( ord($hash[$offset + 2])         <<  8) |
              ord($hash[$offset + 3])
        );
        $code = $truncated % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /* =========================================================
     *  Base32 codec  (RFC 4648, no padding produced)
     * ========================================================= */
    public static function base32Encode(string $bin): string
    {
        if ($bin === '') return '';
        $bits = '';
        $len  = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::ALPHABET_BASE32[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        if ($b32 === '') return '';
        $bits = '';
        $len  = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $idx = strpos(self::ALPHABET_BASE32, $b32[$i]);
            if ($idx === false) continue; // skip whitespace / invalid
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /* =========================================================
     *  RECOVERY CODES (companion to TOTP)
     * ========================================================= */

    /**
     * Generates $count single-use recovery codes (XXXX-XXXX format, 8 digits each).
     * Returns the plaintext codes for one-time display PLUS their bcrypt hashes
     * to be persisted.
     *
     * @return array{codes: array<int,string>, hashes: array<int,string>}
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes  = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
            $codes[]  = $code;
            $hashes[] = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);
        }
        return ['codes' => $codes, 'hashes' => $hashes];
    }

    /**
     * Verifies a recovery code against the persisted hashes.
     * Returns the index of the matched hash on success (caller must remove it),
     * or null if no match.
     *
     * @param array<int,string> $hashes
     */
    public static function findRecoveryCodeIndex(string $code, array $hashes): ?int
    {
        $code = trim($code);
        // Normalize "12345678" → "1234-5678"
        if (preg_match('/^\d{8}$/', $code)) {
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $code)) return null;

        foreach ($hashes as $i => $h) {
            if (is_string($h) && $h !== '' && password_verify($code, $h)) {
                return $i;
            }
        }
        return null;
    }
}
