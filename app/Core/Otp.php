<?php
declare(strict_types=1);

namespace App\Core;

use App\Config\Database;
use App\Sms\SmsFactory;
use RuntimeException;

/**
 * Otp
 * ---
 * Phone-based One-Time-Password challenges.
 *
 *  request($phone, $purpose, $payload, $template):
 *      - Validates phone format
 *      - Rate-limits: 3 OTPs per phone per hour, 10 per IP per hour
 *      - Invalidates any open OTP for the same phone+purpose
 *      - Generates a 6-digit numeric code (cryptographic RNG)
 *      - Persists hash_hmac(sha256, code, APP_KEY) — code is never stored
 *      - Dispatches via SmsFactory::current()
 *      - Returns {challenge_id, phone_masked, expires_in}
 *
 *  verify($id, $code, $purpose):
 *      - Loads challenge, checks consumed/expired/attempts
 *      - On hash_equals match: marks consumed, returns the original payload
 *      - On mismatch: increments attempts; after max → invalidates outright
 *      - Throws RuntimeException with a human-friendly message
 */
final class Otp
{
    private const TTL          = 300; // 5 min
    private const MAX_ATTEMPTS = 5;
    private const CODE_DIGITS  = 6;

    /**
     * @param array<string,mixed> $payload   Arbitrary data to bind to the challenge
     *                                       (e.g. fullname, phone, password_hash)
     * @return array{challenge_id:int,phone_masked:string,expires_in:int}
     */
    public static function request(
        string $phone,
        string $purpose,
        array $payload,
        string $messageTemplate
    ): array {
        $phone = AuthManager::normalizePhone($phone);
        if (!preg_match('/^\+\d{9,15}$/', $phone)) {
            throw new RuntimeException("Telefon raqam noto'g'ri formatda");
        }

        // Rate limiting
        if (!Security::rateLimit('otp:phone:' . $phone, 3, 3600)) {
            throw new RuntimeException(
                "Bu raqamga juda ko'p kod yuborildi. 1 soatdan so'ng urinib ko'ring."
            );
        }
        $ip = Security::clientIp();
        if (!Security::rateLimit('otp:ip:' . $ip, 10, 3600)) {
            throw new RuntimeException(
                "Juda ko'p urinish. Iltimos, keyinroq urinib ko'ring."
            );
        }

        // Invalidate any pending OTP for this phone+purpose so only one is active
        $now = date('Y-m-d H:i:s');
        Database::execute(
            "UPDATE otp_challenges
                SET consumed_at = :n
              WHERE phone = :p AND purpose = :pp AND consumed_at IS NULL",
            [':n' => $now, ':p' => $phone, ':pp' => $purpose]
        );

        // Generate cryptographically random code
        $max  = (int) str_repeat('9', self::CODE_DIGITS);
        $code = str_pad((string) random_int(0, $max), self::CODE_DIGITS, '0', STR_PAD_LEFT);
        $codeHash  = hash_hmac('sha256', $code, Security::appKey());
        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL);

        Database::execute(
            "INSERT INTO otp_challenges
                (phone, code_hash, purpose, payload, max_attempts, expires_at, ip)
             VALUES (:p, :ch, :pp, :pl, :ma, :ex, :ip)",
            [
                ':p'  => $phone,
                ':ch' => $codeHash,
                ':pp' => $purpose,
                ':pl' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ':ma' => self::MAX_ATTEMPTS,
                ':ex' => $expiresAt,
                ':ip' => $ip,
            ]
        );
        $challengeId = (int) Database::master()->lastInsertId();

        // Dispatch SMS (failure here doesn't roll back the challenge —
        // the user can request a resend within the same TTL window).
        $message = str_replace('{code}', $code, $messageTemplate);
        $ok = SmsFactory::current()->send($phone, $message);
        if (!$ok) {
            error_log("[OTP] SMS dispatch failed for challenge $challengeId");
        }

        return [
            'challenge_id' => $challengeId,
            'phone_masked' => self::maskPhone($phone),
            'expires_in'   => self::TTL,
        ];
    }

    /**
     * Verify a code against a challenge_id. Returns the bound payload on success.
     *
     * @return array<string,mixed>
     */
    public static function verify(int $challengeId, string $code, string $purpose): array
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== self::CODE_DIGITS) {
            throw new RuntimeException('Kod ' . self::CODE_DIGITS . ' xonali son bo\'lishi shart');
        }

        $row = Database::selectOne(
            "SELECT id, code_hash, purpose, payload,
                    attempts, max_attempts,
                    consumed_at, expires_at
             FROM otp_challenges
             WHERE id = :id",
            [':id' => $challengeId]
        );
        if ($row === null) {
            throw new RuntimeException('Tasdiqlash kodi topilmadi');
        }
        if ((string) $row['purpose'] !== $purpose) {
            throw new RuntimeException('Kod maqsadi mos kelmaydi');
        }
        if ($row['consumed_at'] !== null) {
            throw new RuntimeException('Kod allaqachon ishlatilgan yoki bekor qilingan');
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            throw new RuntimeException('Kod muddati tugagan');
        }
        if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
            self::invalidate($challengeId);
            throw new RuntimeException("Juda ko'p noto'g'ri urinish — kod bekor qilindi");
        }

        $expected = hash_hmac('sha256', $code, Security::appKey());
        if (!hash_equals((string) $row['code_hash'], $expected)) {
            Database::execute(
                "UPDATE otp_challenges SET attempts = attempts + 1 WHERE id = :id",
                [':id' => $challengeId]
            );
            $remaining = max(0, (int) $row['max_attempts'] - (int) $row['attempts'] - 1);
            throw new RuntimeException(
                "Kod noto'g'ri. Qolgan urinishlar: " . $remaining
            );
        }

        // Success — consume
        Database::execute(
            "UPDATE otp_challenges SET consumed_at = :n WHERE id = :id",
            [':n' => date('Y-m-d H:i:s'), ':id' => $challengeId]
        );

        $payload = $row['payload'] ? json_decode((string) $row['payload'], true) : [];
        return is_array($payload) ? $payload : [];
    }

    /** GC: deletes OTPs older than 1 day. Returns count removed. */
    public static function gc(): int
    {
        $threshold = date('Y-m-d H:i:s', time() - 86400);
        return Database::execute(
            "DELETE FROM otp_challenges WHERE expires_at < :t",
            [':t' => $threshold]
        );
    }

    private static function invalidate(int $id): void
    {
        Database::execute(
            "UPDATE otp_challenges SET consumed_at = :n WHERE id = :id",
            [':n' => date('Y-m-d H:i:s'), ':id' => $id]
        );
    }

    private static function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 8) return $phone;
        return substr($phone, 0, 4) . str_repeat('*', $len - 6) . substr($phone, -2);
    }
}
