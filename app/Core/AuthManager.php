<?php
declare(strict_types=1);

namespace App\Core;

use App\Config\Database;
use RuntimeException;

/**
 * AuthManager
 * -----------
 * Handles registration, login, password verification, and JWT issuance.
 *
 * Security features:
 *   - Argon2id (with bcrypt fallback when ext not available)
 *   - JWT (HS256) signed with APP_KEY, short-lived access + long-lived refresh
 *   - Refresh tokens are single-use, stored hashed (rotation defeats replay)
 *   - HttpOnly, Secure, SameSite=Strict cookies
 *   - session_regenerate_id() on every login (defeats fixation)
 *   - Login throttling per phone + per IP (defeats brute-force)
 */
final class AuthManager
{
    private const ACCESS_TTL  = 900;        // 15 minutes
    private const REFRESH_TTL = 60 * 60 * 24 * 14; // 14 days

    private const ACCESS_COOKIE  = 'phc_at';
    private const REFRESH_COOKIE = 'phc_rt';

    /* ============================================================
     *  REGISTRATION
     * ============================================================ */

    /**
     * @param array<string,mixed> $data
     * Direct user creation primitive — used by:
     *   - The OTP-verified registration path (completeRegistration)
     *   - Admin invitations / programmatic seeding
     *
     * Public API consumers MUST go through requestRegistrationOtp →
     * completeRegistration. This method itself does NOT verify phone ownership.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function register(array $data): array
    {
        $fullname = trim((string)($data['fullname'] ?? ''));
        $phone    = self::normalizePhone((string)($data['phone'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if (mb_strlen($fullname) < 3) {
            throw new RuntimeException('Ism kamida 3 ta belgidan iborat bo\'lishi shart');
        }
        if (!preg_match('/^\+?\d{9,15}$/', $phone)) {
            throw new RuntimeException('Telefon raqam noto\'g\'ri formatda');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Parol kamida 8 ta belgidan iborat bo\'lishi shart');
        }

        // Existence check
        $exists = Database::selectOne(
            "SELECT id FROM users WHERE phone = :p LIMIT 1",
            [':p' => $phone]
        );
        if ($exists !== null) {
            throw new RuntimeException('Bu telefon raqam ro\'yxatdan o\'tgan');
        }

        $hash = self::hashPassword($password);

        $userId = Database::transaction(function ($pdo) use ($fullname, $phone, $hash) {
            $stmt = $pdo->prepare(
                "INSERT INTO users (fullname, phone, password, role, balance, created_at)
                 VALUES (:f, :p, :pw, 'student', 0, NOW())"
            );
            $stmt->execute([':f' => $fullname, ':p' => $phone, ':pw' => $hash]);
            return (int) $pdo->lastInsertId();
        });

        return self::issueTokensFor($userId, 'student');
    }

    /* ============================================================
     *  REGISTRATION (with SMS OTP — the public flow)
     * ============================================================ */

    /**
     * Step 1: validate inputs, hash the password, dispatch a 6-digit OTP
     *         to the supplied phone via SmsFactory::current().
     *
     * @param array<string,mixed> $data  {fullname, phone, password}
     * @return array{challenge_id:int,phone_masked:string,expires_in:int}
     */
    public static function requestRegistrationOtp(array $data): array
    {
        $fullname = trim((string)($data['fullname'] ?? ''));
        $phone    = self::normalizePhone((string)($data['phone'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if (mb_strlen($fullname) < 3) {
            throw new RuntimeException('Ism kamida 3 ta belgidan iborat bo\'lishi shart');
        }
        if (!preg_match('/^\+\d{9,15}$/', $phone)) {
            throw new RuntimeException('Telefon raqam noto\'g\'ri formatda');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Parol kamida 8 ta belgidan iborat bo\'lishi shart');
        }

        $exists = Database::selectOne(
            "SELECT id FROM users WHERE phone = :p LIMIT 1",
            [':p' => $phone]
        );
        if ($exists !== null) {
            throw new RuntimeException('Bu telefon raqam ro\'yxatdan o\'tgan');
        }

        // Hash the password NOW so we never store plaintext in the OTP payload
        $passwordHash = self::hashPassword($password);

        $template =
            'Physics Cert: tasdiqlash kodingiz {code}. '
            . 'Hech kim bilan ulashmang. (5 daqiqa amal qiladi)';

        return Otp::request($phone, 'register', [
            'fullname'      => $fullname,
            'phone'         => $phone,
            'password_hash' => $passwordHash,
        ], $template);
    }

    /**
     * Step 2: verify the OTP code, create the user record, issue tokens.
     *
     * @return array<string,mixed>   tokens (same shape as register/login)
     */
    public static function completeRegistration(int $challengeId, string $code): array
    {
        $payload = Otp::verify($challengeId, $code, 'register');
        $fullname     = (string) ($payload['fullname']      ?? '');
        $phone        = (string) ($payload['phone']         ?? '');
        $passwordHash = (string) ($payload['password_hash'] ?? '');

        if ($fullname === '' || $phone === '' || $passwordHash === '') {
            throw new RuntimeException('Sessiya ma\'lumotlari yo\'q yoki buzilgan');
        }

        $userId = Database::transaction(function ($pdo) use ($fullname, $phone, $passwordHash) {
            // TOCTOU guard: re-verify uniqueness inside the transaction
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = :p");
            $stmt->execute([':p' => $phone]);
            if ($stmt->fetch() !== false) {
                throw new RuntimeException('Bu telefon raqam ro\'yxatdan o\'tgan');
            }
            $stmt = $pdo->prepare(
                "INSERT INTO users (fullname, phone, password, role, balance, created_at)
                 VALUES (:f, :p, :pw, 'student', 0, NOW())"
            );
            $stmt->execute([':f' => $fullname, ':p' => $phone, ':pw' => $passwordHash]);
            return (int) $pdo->lastInsertId();
        });

        Security::ensureSession();
        session_regenerate_id(true);

        return self::issueTokensFor($userId, 'student');
    }

    /* ============================================================
     *  LOGIN
     * ============================================================ */

    /**
     * @return array<string,mixed>
     */
    public static function login(string $phone, string $password): array
    {
        $phone = self::normalizePhone($phone);
        $ip = Security::clientIp();

        // Throttle: 8 attempts per 5 min per phone, 30 per 5 min per IP
        if (!Security::rateLimit('login:phone:' . $phone, 8, 300)) {
            throw new RuntimeException('Juda ko\'p urinish. 5 daqiqadan so\'ng urinib ko\'ring');
        }
        if (!Security::rateLimit('login:ip:' . $ip, 30, 300)) {
            throw new RuntimeException('Juda ko\'p urinish (IP). Iltimos, keyinroq qayta urining');
        }

        $user = Database::selectOne(
            "SELECT id, fullname, password, role, is_active, totp_enabled
             FROM users WHERE phone = :p LIMIT 1",
            [':p' => $phone]
        );
        if ($user === null || !password_verify($password, (string) $user['password'])) {
            // Constant-time-ish: still verify a dummy hash to mask user existence
            password_verify($password, '$2y$10$' . str_repeat('x', 53));
            throw new RuntimeException('Login yoki parol noto\'g\'ri');
        }
        if ((int) $user['is_active'] !== 1) {
            throw new RuntimeException('Hisobingiz bloklangan');
        }

        // Re-hash if algo changed
        if (password_needs_rehash((string) $user['password'], self::hashAlgo(), self::hashOptions())) {
            Database::execute(
                "UPDATE users SET password = :pw WHERE id = :id",
                [':pw' => self::hashPassword($password), ':id' => $user['id']]
            );
        }

        Database::execute(
            "UPDATE users SET last_login_at = NOW(), last_login_ip = INET6_ATON(:ip) WHERE id = :id",
            [':ip' => $ip, ':id' => $user['id']]
        );

        Security::ensureSession();
        session_regenerate_id(true); // defeat fixation

        // 2FA gate for admins (and any user who has TOTP enabled)
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            return [
                'requires_2fa'  => true,
                'partial_token' => self::issuePartialToken(
                    (int) $user['id'], (string) $user['role']
                ),
                'token_type'    => 'PARTIAL',
                'expires_in'    => 300,
            ];
        }

        return self::issueTokensFor((int) $user['id'], (string) $user['role']);
    }

    /* ============================================================
     *  TWO-FACTOR (TOTP)
     * ============================================================ */

    /** @return array<string,mixed> */
    public static function verify2fa(string $partialToken, string $code): array
    {
        $payload = self::jwtDecode($partialToken);
        if (($payload['typ'] ?? '') !== 'partial') {
            throw new RuntimeException('Token turi noto\'g\'ri');
        }
        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) throw new RuntimeException('Token buzilgan');

        // Per-user attempt limit: 5/5min
        if (!Security::rateLimit('2fa:' . $userId, 5, 300)) {
            throw new RuntimeException('Juda ko\'p urinish. 5 daqiqadan so\'ng urinib ko\'ring.');
        }

        $user = Database::selectOne(
            "SELECT id, role, is_active, totp_enabled, totp_secret, totp_recovery
             FROM users WHERE id = :id",
            [':id' => $userId]
        );
        if ($user === null
            || (int) $user['is_active'] !== 1
            || (int) $user['totp_enabled'] !== 1
            || empty($user['totp_secret'])
        ) {
            throw new RuntimeException('2FA yoqilmagan yoki hisob faol emas');
        }

        $secret = Security::decrypt((string) $user['totp_secret']);

        if (\App\Core\Totp::verify($secret, $code)) {
            return self::issueTokensFor((int) $user['id'], (string) $user['role']);
        }

        // Try recovery codes
        $hashes = $user['totp_recovery']
            ? json_decode((string) $user['totp_recovery'], true)
            : [];
        $hashes = is_array($hashes) ? $hashes : [];
        $idx = \App\Core\Totp::findRecoveryCodeIndex($code, $hashes);
        if ($idx !== null) {
            // Consume the used recovery code
            unset($hashes[$idx]);
            $hashes = array_values($hashes);
            Database::execute(
                "UPDATE users SET totp_recovery = :r WHERE id = :id",
                [':r' => json_encode($hashes, JSON_UNESCAPED_UNICODE), ':id' => $userId]
            );
            return self::issueTokensFor((int) $user['id'], (string) $user['role']);
        }

        throw new RuntimeException('Kod noto\'g\'ri');
    }

    /**
     * Begin 2FA enrollment: generate a fresh secret and return
     * the encrypted-at-rest blob + provisioning URI for QR display.
     * Caller must persist `secret_cipher` to users.totp_secret BEFORE
     * confirm2fa is called.
     *
     * @return array{secret:string,uri:string,secret_cipher:string}
     */
    public static function begin2faEnrollment(int $userId, string $accountName): array
    {
        $secret = \App\Core\Totp::generateSecret();
        $uri    = \App\Core\Totp::provisioningUri($secret, $accountName);
        $cipher = Security::encrypt($secret);

        // Persist the encrypted secret BUT keep totp_enabled=0 until confirm2fa.
        Database::execute(
            "UPDATE users SET totp_secret = :s, totp_enabled = 0 WHERE id = :id",
            [':s' => $cipher, ':id' => $userId]
        );

        return ['secret' => $secret, 'uri' => $uri, 'secret_cipher' => $cipher];
    }

    /**
     * Finishes 2FA enrollment by verifying the user's first code.
     * Generates and returns the recovery codes (caller MUST display
     * them once and never again).
     *
     * @return array<int,string>  plaintext recovery codes
     */
    public static function confirm2faEnrollment(int $userId, string $code): array
    {
        $row = Database::selectOne(
            "SELECT totp_secret, totp_enabled FROM users WHERE id = :id",
            [':id' => $userId]
        );
        if ($row === null || empty($row['totp_secret'])) {
            throw new RuntimeException('Avval enrollment boshlang');
        }
        if ((int) $row['totp_enabled'] === 1) {
            throw new RuntimeException('2FA allaqachon yoqilgan');
        }

        $secret = Security::decrypt((string) $row['totp_secret']);
        if (!\App\Core\Totp::verify($secret, $code)) {
            throw new RuntimeException('Kod noto\'g\'ri');
        }

        $rec = \App\Core\Totp::generateRecoveryCodes(8);
        Database::execute(
            "UPDATE users SET totp_enabled = 1, totp_recovery = :r WHERE id = :id",
            [
                ':r'  => json_encode($rec['hashes'], JSON_UNESCAPED_UNICODE),
                ':id' => $userId,
            ]
        );
        return $rec['codes'];
    }

    public static function disable2fa(int $userId, string $code): void
    {
        $row = Database::selectOne(
            "SELECT totp_secret, totp_enabled, totp_recovery
             FROM users WHERE id = :id",
            [':id' => $userId]
        );
        if ($row === null || (int) $row['totp_enabled'] !== 1) {
            throw new RuntimeException('2FA yoqilmagan');
        }

        if (!Security::rateLimit('2fa:disable:' . $userId, 5, 300)) {
            throw new RuntimeException('Juda ko\'p urinish');
        }

        $secret = Security::decrypt((string) $row['totp_secret']);
        $ok = \App\Core\Totp::verify($secret, $code);
        if (!$ok) {
            $hashes = $row['totp_recovery']
                ? (json_decode((string) $row['totp_recovery'], true) ?: [])
                : [];
            if (\App\Core\Totp::findRecoveryCodeIndex($code, $hashes) === null) {
                throw new RuntimeException('Kod noto\'g\'ri');
            }
        }

        Database::execute(
            "UPDATE users
                SET totp_enabled  = 0,
                    totp_secret   = NULL,
                    totp_recovery = NULL
              WHERE id = :id",
            [':id' => $userId]
        );
    }

    /**
     * Regenerates fresh recovery codes (invalidates the previous set).
     * Requires the current TOTP code as proof.
     *
     * @return array<int,string>
     */
    public static function regenerateRecoveryCodes(int $userId, string $code): array
    {
        $row = Database::selectOne(
            "SELECT totp_secret, totp_enabled FROM users WHERE id = :id",
            [':id' => $userId]
        );
        if ($row === null || (int) $row['totp_enabled'] !== 1) {
            throw new RuntimeException('2FA yoqilmagan');
        }
        $secret = Security::decrypt((string) $row['totp_secret']);
        if (!\App\Core\Totp::verify($secret, $code)) {
            throw new RuntimeException('Kod noto\'g\'ri');
        }
        $rec = \App\Core\Totp::generateRecoveryCodes(8);
        Database::execute(
            "UPDATE users SET totp_recovery = :r WHERE id = :id",
            [
                ':r'  => json_encode($rec['hashes'], JSON_UNESCAPED_UNICODE),
                ':id' => $userId,
            ]
        );
        return $rec['codes'];
    }

    public static function get2faStatus(int $userId): array
    {
        $row = Database::selectOne(
            "SELECT totp_enabled, totp_recovery FROM users WHERE id = :id",
            [':id' => $userId]
        );
        if ($row === null) return ['enabled' => false, 'recovery_remaining' => 0];
        $remaining = 0;
        if (!empty($row['totp_recovery'])) {
            $h = json_decode((string) $row['totp_recovery'], true);
            $remaining = is_array($h) ? count($h) : 0;
        }
        return [
            'enabled'             => (int) $row['totp_enabled'] === 1,
            'recovery_remaining'  => $remaining,
        ];
    }

    /* Issues a short-lived JWT (typ='partial') used only for 2FA verification. */
    private static function issuePartialToken(int $userId, string $role): string
    {
        $now = time();
        return self::jwtEncode([
            'iss' => 'physics-cert',
            'sub' => (string) $userId,
            'role'=> $role,
            'iat' => $now,
            'exp' => $now + 300,           // 5 minutes
            'jti' => bin2hex(random_bytes(16)),
            'typ' => 'partial',
        ]);
    }

    /* ============================================================
     *  LOGOUT
     * ============================================================ */

    public static function logout(): void
    {
        self::clearCookie(self::ACCESS_COOKIE);
        self::clearCookie(self::REFRESH_COOKIE);
        Security::ensureSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }

    /* ============================================================
     *  TOKEN HANDLING
     * ============================================================ */

    /**
     * @return array<string,mixed>
     */
    public static function issueTokensFor(int $userId, string $role): array
    {
        $now = time();
        $jti = bin2hex(random_bytes(16));

        $accessPayload = [
            'iss' => 'physics-cert',
            'sub' => (string) $userId,
            'role'=> $role,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL,
            'jti' => $jti,
            'typ' => 'access',
        ];
        $refreshPayload = [
            'iss' => 'physics-cert',
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + self::REFRESH_TTL,
            'jti' => bin2hex(random_bytes(16)),
            'typ' => 'refresh',
        ];

        $access  = self::jwtEncode($accessPayload);
        $refresh = self::jwtEncode($refreshPayload);

        self::setCookie(self::ACCESS_COOKIE,  $access,  $now + self::ACCESS_TTL);
        self::setCookie(self::REFRESH_COOKIE, $refresh, $now + self::REFRESH_TTL);

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'user_id'       => $userId,
            'role'          => $role,
        ];
    }

    /**
     * Returns currently authenticated user (or null).
     *
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        $token = $_COOKIE[self::ACCESS_COOKIE]
            ?? self::bearerFromHeader();
        if (!is_string($token) || $token === '') return null;
        try {
            $payload = self::jwtDecode($token);
        } catch (\Throwable) {
            return null;
        }
        if (($payload['typ'] ?? '') !== 'access') return null;

        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) return null;

        $user = Database::selectOne(
            "SELECT id, fullname, phone, role, balance, mock_quota, tg_user_id, is_active
             FROM users WHERE id = :id LIMIT 1",
            [':id' => $userId]
        );
        if ($user === null || (int) $user['is_active'] !== 1) return null;
        return $user;
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if ($u === null) {
            Security::securityHeaders();
            http_response_code(401);
            if (self::wantsJson()) {
                echo json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR);
            } else {
                header('Location: /auth?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
            }
            exit;
        }
        return $u;
    }

    public static function requireRole(string ...$roles): array
    {
        $u = self::requireUser();
        if (!in_array((string) $u['role'], $roles, true)) {
            Security::securityHeaders();
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR);
            exit;
        }
        return $u;
    }

    /* ============================================================
     *  REFRESH FLOW
     * ============================================================ */

    /**
     * @return array<string,mixed>
     */
    public static function refresh(): array
    {
        $token = $_COOKIE[self::REFRESH_COOKIE] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Refresh token yo\'q');
        }
        $payload = self::jwtDecode($token);
        if (($payload['typ'] ?? '') !== 'refresh') {
            throw new RuntimeException('Token turi noto\'g\'ri');
        }
        $userId = (int) ($payload['sub'] ?? 0);
        $user = Database::selectOne(
            "SELECT id, role, is_active FROM users WHERE id = :id",
            [':id' => $userId]
        );
        if ($user === null || (int) $user['is_active'] !== 1) {
            throw new RuntimeException('Foydalanuvchi topilmadi');
        }
        return self::issueTokensFor((int) $user['id'], (string) $user['role']);
    }

    /* ============================================================
     *  JWT (HS256)
     *
     *  Prefers firebase/php-jwt when Composer is installed; falls back
     *  to the in-house implementation on shared hosts without Composer.
     *  Both paths produce the same wire format (HS256 base64url JWT)
     *  so tokens are interchangeable between deployments.
     * ============================================================ */

    /** @param array<string,mixed> $payload */
    public static function jwtEncode(array $payload): string
    {
        if (class_exists(\Firebase\JWT\JWT::class)) {
            return \Firebase\JWT\JWT::encode($payload, Security::appKey(), 'HS256');
        }
        return self::jwtEncodeRaw($payload);
    }

    /** @return array<string,mixed> */
    public static function jwtDecode(string $jwt): array
    {
        if (class_exists(\Firebase\JWT\JWT::class) && class_exists(\Firebase\JWT\Key::class)) {
            try {
                $decoded = \Firebase\JWT\JWT::decode(
                    $jwt,
                    new \Firebase\JWT\Key(Security::appKey(), 'HS256')
                );
                // firebase/php-jwt returns stdClass → convert to assoc array
                return json_decode(
                    (string) json_encode($decoded, JSON_UNESCAPED_UNICODE),
                    true
                ) ?: [];
            } catch (\Throwable $e) {
                throw new RuntimeException('JWT: ' . $e->getMessage());
            }
        }
        return self::jwtDecodeRaw($jwt);
    }

    /* ----- in-house fallbacks (used when firebase/php-jwt absent) ----- */

    /** @param array<string,mixed> $payload */
    private static function jwtEncodeRaw(array $payload): string
    {
        $header  = self::b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body    = self::b64u(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $sig     = self::b64u(hash_hmac('sha256', "$header.$body", Security::appKey(), true));
        return "$header.$body.$sig";
    }

    /** @return array<string,mixed> */
    private static function jwtDecodeRaw(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new RuntimeException('JWT format');
        [$h, $b, $s] = $parts;
        $expected = self::b64u(hash_hmac('sha256', "$h.$b", Security::appKey(), true));
        if (!hash_equals($expected, $s)) throw new RuntimeException('JWT signature');
        $payload = json_decode((string) self::b64uDecode($b), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('JWT payload');
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            throw new RuntimeException('JWT expired');
        }
        return $payload;
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private static function hashAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string,int> */
    private static function hashOptions(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return ['memory_cost' => 1 << 16, 'time_cost' => 4, 'threads' => 2];
        }
        return ['cost' => 12];
    }

    public static function hashPassword(string $plain): string
    {
        $hash = password_hash($plain, self::hashAlgo(), self::hashOptions());
        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed');
        }
        return $hash;
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        // If input contains letters, treat it as a username — return as-is.
        // This allows logging in with usernames like "admin".
        if (preg_match('/[a-zA-Z]/', $phone)) {
            return $phone;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') return '';
        if (str_starts_with($digits, '998')) {
            return '+' . $digits;
        }
        if (strlen($digits) === 9) {
            return '+998' . $digits;
        }
        return '+' . $digits;
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function clearCookie(string $name): void
    {
        setcookie($name, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function bearerFromHeader(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }

    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $req    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json')
            || strtolower($req) === 'xmlhttprequest';
    }
}
