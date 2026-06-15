<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthManager;
use App\Core\Security;
use Throwable;

/**
 * AuthController
 * --------------
 * - GET  /auth         → render login/register view
 * - POST /api/auth/login
 * - POST /api/auth/register
 * - POST /api/auth/logout
 * - POST /api/auth/refresh
 * - GET  /api/auth/me
 */
final class AuthController
{
    public function showAuthPage(): void
    {
        Security::ensureSession();
        Security::securityHeaders();
        // If already logged in, redirect appropriately
        $u = AuthManager::user();
        if ($u !== null) {
            header('Location: ' . ($u['role'] === 'admin' ? '/admin' : '/profile'));
            return;
        }
        $csrfLogin    = Security::csrfToken('auth:login');
        $csrfRegister = Security::csrfToken('auth:register');
        require __DIR__ . '/../../views/auth.phtml';
    }

    public function login(): void
    {
        $body = self::readJson();
        Security::requireCsrf('auth:login');
        try {
            $tokens = AuthManager::login(
                (string) ($body['phone']    ?? ''),
                (string) ($body['password'] ?? '')
            );
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 401);
        }
        self::json(['ok' => true, 'data' => $tokens]);
    }

    public function register(): void
    {
        // Public registration is now OTP-gated. Step 1 — request OTP.
        $body = self::readJson();
        Security::requireCsrf('auth:register');
        try {
            $result = AuthManager::requestRegistrationOtp(Security::sanitize($body));
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }
        // No tokens yet — just challenge metadata for the client to render
        // the OTP step.
        self::json(['ok' => true, 'data' => $result]);
    }

    public function verifyRegistration(): void
    {
        // Public registration step 2 — submit OTP.
        $body = self::readJson();
        Security::requireCsrf('auth:register');
        $challengeId = (int) ($body['challenge_id'] ?? 0);
        $code        = (string) ($body['code'] ?? '');
        try {
            $tokens = AuthManager::completeRegistration($challengeId, $code);
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }
        self::json(['ok' => true, 'data' => $tokens]);
    }

    public function resendRegistrationOtp(): void
    {
        // Allows re-issuing the OTP if SMS didn't arrive. Same payload as `register`,
        // separate intent + tighter rate limit (handled inside Otp::request).
        $body = self::readJson();
        Security::requireCsrf('auth:register');
        try {
            $result = AuthManager::requestRegistrationOtp(Security::sanitize($body));
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }
        self::json(['ok' => true, 'data' => $result]);
    }

    public function logout(): void
    {
        AuthManager::logout();
        self::json(['ok' => true]);
    }

    /* ============================================================
     *  2FA (TOTP)
     * ============================================================ */

    public function verify2fa(): void
    {
        $body  = self::readJson();
        $token = (string) ($body['partial_token'] ?? '');
        $code  = (string) ($body['code'] ?? '');
        try {
            $tokens = AuthManager::verify2fa($token, $code);
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 401);
        }
        self::json(['ok' => true, 'data' => $tokens]);
    }

    public function tfaStatus(): void
    {
        $user = AuthManager::requireUser();
        self::json(AuthManager::get2faStatus((int) $user['id']));
    }

    public function tfaBegin(): void
    {
        $user = AuthManager::requireUser();
        Security::requireCsrf('admin', singleUse: false);
        $accountName = (string) ($user['phone'] ?? ('user-' . $user['id']));
        try {
            $r = AuthManager::begin2faEnrollment((int) $user['id'], $accountName);
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }
        // Don't return the cipher to the client; it's already in DB.
        // Return only what's needed to render the QR.
        self::json([
            'ok'     => true,
            'secret' => $r['secret'],
            'uri'    => $r['uri'],
        ]);
    }

    public function tfaConfirm(): void
    {
        $user = AuthManager::requireUser();
        Security::requireCsrf('admin', singleUse: false);
        $body = self::readJson();
        $code = (string) ($body['code'] ?? '');
        try {
            $codes = AuthManager::confirm2faEnrollment((int) $user['id'], $code);
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }
        self::json(['ok' => true, 'recovery_codes' => $codes]);
    }

    public function tfaDisable(): void
    {
        $user = AuthManager::requireUser();
        Security::requireCsrf('admin', singleUse: false);
        $body = self::readJson();
        $code = (string) ($body['code'] ?? '');
        try {
            AuthManager::disable2fa((int) $user['id'], $code);
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }
        self::json(['ok' => true]);
    }

    public function tfaRegenerate(): void
    {
        $user = AuthManager::requireUser();
        Security::requireCsrf('admin', singleUse: false);
        $body = self::readJson();
        $code = (string) ($body['code'] ?? '');
        try {
            $codes = AuthManager::regenerateRecoveryCodes((int) $user['id'], $code);
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 422);
        }
        self::json(['ok' => true, 'recovery_codes' => $codes]);
    }

    public function refresh(): void
    {
        try {
            $tokens = AuthManager::refresh();
        } catch (Throwable $e) {
            self::json(['error' => $e->getMessage()], 401);
        }
        self::json(['ok' => true, 'data' => $tokens]);
    }

    public function me(): void
    {
        $u = AuthManager::user();
        if ($u === null) self::json(['error' => 'Unauthorized'], 401);
        self::json(['user' => $u]);
    }

    /* ============================================================
     *  PROFILE PAGE  (HTML)
     * ============================================================ */
    public function showProfile(): void
    {
        $user = AuthManager::requireUser();

        // Stats
        $stats = \App\Config\Database::selectOne(
            "SELECT COUNT(*) AS total,
                    AVG(score)  AS avg_score,
                    MAX(score)  AS best_score
             FROM user_exams
             WHERE user_id = :u AND status = 'submitted'",
            [':u' => $user['id']]
        );
        $recent = \App\Config\Database::select(
            "SELECT ue.id, ue.score, ue.correct_count, ue.wrong_count,
                    ue.skipped_count, ue.section_analysis, ue.finished_at,
                    e.title AS exam_title
             FROM user_exams ue
             JOIN exams e ON e.id = ue.exam_id
             WHERE ue.user_id = :u AND ue.status = 'submitted'
             ORDER BY ue.finished_at DESC
             LIMIT 10",
            [':u' => $user['id']]
        );
        foreach ($recent as &$r) {
            $r['section_analysis'] = $r['section_analysis']
                ? json_decode((string) $r['section_analysis'], true)
                : [];
        }
        unset($r);

        $logoRow = \App\Config\Database::selectOne(
            "SELECT `value` FROM system_settings WHERE `key` = 'site_logo'"
        );
        $logo = (string) ($logoRow['value'] ?? '/uploads/logo/default.png');

        $csrfBuy = Security::csrfToken('buy:tariff');

        Security::securityHeaders();
        require __DIR__ . '/../../views/profile.phtml';
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    /** @return array<string,mixed> */
    private static function readJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        return $_POST;
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $code = 200): never
    {
        Security::securityHeaders();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
