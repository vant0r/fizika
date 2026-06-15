<?php
declare(strict_types=1);

namespace App\Bot;

use App\Config\Database;
use App\Core\Security;
use RuntimeException;
use Throwable;

/**
 * TelegramWebhook
 * ---------------
 * Production webhook for the P2P payment bot.
 *
 * Flow:
 *   1) /start  → Welcome + ask "ID raqamingizni yuboring"
 *   2) User sends ID → bot resolves user, asks for confirmation (Inline keyboard)
 *   3) On confirm → bot lists active tariffs as inline buttons
 *   4) On tariff pick → bot shows current Humo/Visa cards, asks for screenshot
 *   5) User sends photo → bot saves screenshot to /public/uploads/screenshots/
 *      and inserts payments row with status='pending'
 *   6) Admin approves via panel → AdminController triggers
 *      sendNotification($tg_user_id, "to'lov tasdiqlandi")
 *
 * Webhook security:
 *   - Telegram secret token header verification
 *   - Per-IP and per-tg_user rate limiting
 *   - All input is escape()-d before being inserted into MarkdownV2
 */
final class TelegramWebhook
{
    private const TG_API = 'https://api.telegram.org/bot';
    private const STATE_IDLE          = 'idle';
    private const STATE_AWAIT_USER_ID = 'await_user_id';
    private const STATE_AWAIT_CONFIRM = 'await_confirm';
    private const STATE_AWAIT_TARIFF  = 'await_tariff';
    private const STATE_AWAIT_PROOF   = 'await_proof';

    /* ============================================================
     *  ENTRY POINT
     * ============================================================ */

    public static function handle(): void
    {
        try {
            self::verifySecret();
            $update = self::readUpdate();

            if (isset($update['callback_query'])) {
                self::handleCallback($update['callback_query']);
            } elseif (isset($update['message'])) {
                self::handleMessage($update['message']);
            }
            http_response_code(200);
            echo 'OK';
        } catch (Throwable $e) {
            error_log('[TG_WEBHOOK_ERROR] ' . $e->getMessage());
            http_response_code(200); // never let TG retry-storm us
            echo 'OK';
        }
    }

    private static function verifySecret(): void
    {
        $expected = (string) getenv('TG_WEBHOOK_SECRET');
        $got      = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if ($expected === '' || !hash_equals($expected, $got)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        $ip = Security::clientIp();
        if (!Security::rateLimit('tgwh:ip:' . $ip, 600, 60)) {
            http_response_code(429);
            exit;
        }
    }

    /** @return array<string,mixed> */
    private static function readUpdate(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $update = json_decode($raw, true);
        if (!is_array($update)) {
            throw new RuntimeException('Invalid update payload');
        }
        return $update;
    }

    /* ============================================================
     *  MESSAGE HANDLER
     * ============================================================ */

    /** @param array<string,mixed> $msg */
    private static function handleMessage(array $msg): void
    {
        $from   = $msg['from'] ?? [];
        $tgId   = (int) ($from['id'] ?? 0);
        $chatId = (int) ($msg['chat']['id'] ?? $tgId);
        if ($tgId <= 0) return;

        if (!Security::rateLimit('tgwh:user:' . $tgId, 30, 60)) {
            self::sendMessage($chatId, "Juda ko'p so'rov. Iltimos biroz kuting.");
            return;
        }

        $text = trim((string) ($msg['text'] ?? ''));
        $session = self::loadSession($tgId);

        // /start command
        if (str_starts_with($text, '/start')) {
            self::saveSession($tgId, self::STATE_AWAIT_USER_ID, []);
            self::sendMessage(
                $chatId,
                "Xush kelibsiz! 🎓\n\n"
                . "Ushbu bot orqali Fizika sertifikat platformasiga to'lov qilishingiz mumkin.\n\n"
                . "Iltimos, sayt profilingizdagi *ID raqamingizni* yuboring."
            );
            return;
        }

        if (str_starts_with($text, '/cancel')) {
            self::saveSession($tgId, self::STATE_IDLE, []);
            self::sendMessage($chatId, "Bekor qilindi. /start bilan qaytadan boshlang.");
            return;
        }

        // Photo (screenshot) handling
        if (isset($msg['photo']) && $session['state'] === self::STATE_AWAIT_PROOF) {
            self::handleScreenshot($chatId, $tgId, $msg, $session);
            return;
        }

        // State machine
        switch ($session['state']) {
            case self::STATE_AWAIT_USER_ID:
                self::handleUserIdInput($chatId, $tgId, $text);
                return;

            case self::STATE_AWAIT_CONFIRM:
                self::sendMessage($chatId, "Iltimos, yuqoridagi tugmalardan birini tanlang.");
                return;

            case self::STATE_AWAIT_TARIFF:
                self::sendMessage($chatId, "Iltimos, yuqoridagi tariflardan birini tanlang.");
                return;

            case self::STATE_AWAIT_PROOF:
                self::sendMessage($chatId, "Iltimos, to'lov *chekining rasmini* yuboring 📸");
                return;

            default:
                self::sendMessage($chatId, "Boshlash uchun /start ni bosing.");
        }
    }

    /* ============================================================
     *  STATE: AWAIT_USER_ID
     * ============================================================ */

    private static function handleUserIdInput(int $chatId, int $tgId, string $text): void
    {
        if (!ctype_digit($text)) {
            self::sendMessage($chatId, "ID faqat raqamlardan iborat bo'lishi kerak. Qayta urinib ko'ring.");
            return;
        }
        $userId = (int) $text;
        $user = Database::selectOne(
            "SELECT id, fullname, phone FROM users WHERE id = :id LIMIT 1",
            [':id' => $userId]
        );
        if ($user === null) {
            self::sendMessage($chatId, "❌ Bunday ID topilmadi. ID raqamni tekshirib qayta yuboring.");
            return;
        }

        self::saveSession($tgId, self::STATE_AWAIT_CONFIRM, ['user_id' => $userId]);

        $name  = self::escapeMd((string) $user['fullname']);
        $phone = self::escapeMd(self::maskPhone((string) $user['phone']));
        self::sendMessage(
            $chatId,
            "👤 Ma'lumotlarni tasdiqlang:\n\n"
            . "*Ism:* {$name}\n"
            . "*Telefon:* {$phone}\n"
            . "*ID:* `{$userId}`\n\n"
            . "Bu siz bo'lasizmi?",
            self::keyboard([
                [['text' => '✅ Ha, bu menman', 'callback_data' => 'confirm:' . $userId]],
                [['text' => '❌ Yo\'q', 'callback_data' => 'cancel']],
            ])
        );
    }

    /* ============================================================
     *  CALLBACK QUERIES (inline buttons)
     * ============================================================ */

    /** @param array<string,mixed> $cb */
    private static function handleCallback(array $cb): void
    {
        $tgId   = (int) ($cb['from']['id'] ?? 0);
        $chatId = (int) ($cb['message']['chat']['id'] ?? $tgId);
        $data   = (string) ($cb['data'] ?? '');
        $cbId   = (string) ($cb['id'] ?? '');
        self::answerCallback($cbId);

        if ($tgId <= 0) return;

        $session = self::loadSession($tgId);

        if ($data === 'cancel') {
            self::saveSession($tgId, self::STATE_IDLE, []);
            self::sendMessage($chatId, "Bekor qilindi. /start bilan qayta boshlang.");
            return;
        }

        if (str_starts_with($data, 'confirm:')) {
            $userId = (int) substr($data, 8);
            // Bind tg_user_id to user record
            Database::execute(
                "UPDATE users SET tg_user_id = :tg WHERE id = :id",
                [':tg' => $tgId, ':id' => $userId]
            );
            self::saveSession($tgId, self::STATE_AWAIT_TARIFF, ['user_id' => $userId]);
            self::sendTariffMenu($chatId);
            return;
        }

        if (str_starts_with($data, 'tariff:')) {
            $tariffId = (int) substr($data, 7);
            $tariff = Database::selectOne(
                "SELECT id, name, price, mock_count FROM tariffs WHERE id = :id AND is_active = 1",
                [':id' => $tariffId]
            );
            if ($tariff === null) {
                self::sendMessage($chatId, "Tarif topilmadi. /start bilan qayta urinib ko'ring.");
                return;
            }
            $payload = $session['payload'];
            $payload['tariff_id'] = $tariffId;
            $payload['amount']    = (float) $tariff['price'];
            self::saveSession($tgId, self::STATE_AWAIT_PROOF, $payload);

            $cards = self::loadCards();
            $name  = self::escapeMd((string) $tariff['name']);
            $price = self::escapeMd(number_format((float) $tariff['price'], 0, '.', ' '));
            $humo  = self::escapeMd($cards['humo']);
            $visa  = self::escapeMd($cards['visa']);
            $hold  = self::escapeMd($cards['holder']);

            self::sendMessage(
                $chatId,
                "💳 *To'lov:* {$name}\n"
                . "*Summa:* {$price} so'm\n\n"
                . "Quyidagi karta raqamlaridan biriga to'lov qiling va *chek rasmini* shu botga yuboring:\n\n"
                . "*HUMO:* `{$humo}`\n"
                . "*VISA:* `{$visa}`\n"
                . "*Karta egasi:* {$hold}\n\n"
                . "📸 Endi to'lov chekining rasmini yuboring."
            );
            return;
        }
    }

    /* ============================================================
     *  STATE: AWAIT_PROOF
     * ============================================================ */

    /**
     * @param array<string,mixed> $msg
     * @param array<string,mixed> $session
     */
    private static function handleScreenshot(int $chatId, int $tgId, array $msg, array $session): void
    {
        $payload = $session['payload'];
        if (empty($payload['user_id']) || empty($payload['tariff_id'])) {
            self::sendMessage($chatId, "Sessiya muddati o'tdi. /start dan qayta boshlang.");
            self::saveSession($tgId, self::STATE_IDLE, []);
            return;
        }

        $photos = $msg['photo'];
        $largest = end($photos);
        $fileId  = (string) ($largest['file_id'] ?? '');
        if ($fileId === '') {
            self::sendMessage($chatId, "Rasmni o'qib bo'lmadi, qayta yuboring.");
            return;
        }

        $localPath = self::downloadPhoto($fileId);
        if ($localPath === null) {
            self::sendMessage($chatId, "Rasmni saqlab bo'lmadi. Qayta urinib ko'ring.");
            return;
        }

        Database::execute(
            "INSERT INTO payments (user_id, tariff_id, amount, screenshot_path, status, created_at)
             VALUES (:uid, :tid, :amt, :p, 'pending', NOW())",
            [
                ':uid' => (int) $payload['user_id'],
                ':tid' => (int) $payload['tariff_id'],
                ':amt' => (float) $payload['amount'],
                ':p'   => $localPath,
            ]
        );

        self::saveSession($tgId, self::STATE_IDLE, []);
        self::sendMessage(
            $chatId,
            "✅ *Chek qabul qilindi!*\n\n"
            . "To'lovingiz administrator tomonidan tekshiriladi. "
            . "Tasdiqlangach, sizga shu yerga xabar yuboriladi."
        );
    }

    private static function downloadPhoto(string $fileId): ?string
    {
        $token = (string) getenv('TG_BOT_TOKEN');
        if ($token === '') return null;

        $infoUrl = self::TG_API . $token . '/getFile?file_id=' . urlencode($fileId);
        $info = self::httpGet($infoUrl);
        $json = json_decode($info ?: '', true);
        if (!is_array($json) || empty($json['ok']) || empty($json['result']['file_path'])) {
            return null;
        }
        $remote = 'https://api.telegram.org/file/bot' . $token . '/' . $json['result']['file_path'];
        $bytes = self::httpGet($remote);
        if ($bytes === null) return null;

        $dir = realpath(__DIR__ . '/../../public') . '/uploads/screenshots';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $ext = pathinfo($json['result']['file_path'], PATHINFO_EXTENSION) ?: 'jpg';
        $name = sprintf('%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(8)), $ext);
        $abs  = $dir . '/' . $name;
        if (file_put_contents($abs, $bytes) === false) return null;

        return '/uploads/screenshots/' . $name;
    }

    /* ============================================================
     *  TARIFF MENU
     * ============================================================ */

    private static function sendTariffMenu(int $chatId): void
    {
        $tariffs = Database::select(
            "SELECT id, name, price, mock_count FROM tariffs
             WHERE is_active = 1 ORDER BY sort_order ASC, price ASC"
        );
        if ($tariffs === []) {
            self::sendMessage($chatId, "Tariflar topilmadi. Administrator bilan bog'laning.");
            return;
        }
        $rows = [];
        foreach ($tariffs as $t) {
            $price = number_format((float) $t['price'], 0, '.', ' ');
            $rows[] = [[
                'text'          => sprintf('%s — %s so\'m (%d mock)', $t['name'], $price, $t['mock_count']),
                'callback_data' => 'tariff:' . $t['id'],
            ]];
        }
        $rows[] = [['text' => '❌ Bekor qilish', 'callback_data' => 'cancel']];

        self::sendMessage(
            $chatId,
            "📋 *Tarifni tanlang:*",
            self::keyboard($rows)
        );
    }

    /* ============================================================
     *  PUBLIC API — used by AdminController to notify on approval
     * ============================================================ */

    public static function sendNotification(int $tgUserId, string $text): bool
    {
        if ($tgUserId <= 0) return false;
        $resp = self::sendMessage($tgUserId, $text);
        return $resp !== null;
    }

    /* ============================================================
     *  TG SESSION STORAGE (DB-backed)
     * ============================================================ */

    /** @return array{state:string,payload:array<string,mixed>} */
    private static function loadSession(int $tgId): array
    {
        $row = Database::selectOne(
            "SELECT state, payload FROM tg_sessions WHERE tg_user_id = :id",
            [':id' => $tgId]
        );
        if ($row === null) {
            return ['state' => self::STATE_IDLE, 'payload' => []];
        }
        $payload = [];
        if (!empty($row['payload'])) {
            $decoded = json_decode((string) $row['payload'], true);
            if (is_array($decoded)) $payload = $decoded;
        }
        return ['state' => (string) $row['state'], 'payload' => $payload];
    }

    /** @param array<string,mixed> $payload */
    private static function saveSession(int $tgId, string $state, array $payload): void
    {
        Database::execute(
            "INSERT INTO tg_sessions (tg_user_id, state, payload)
             VALUES (:id, :s, :p)
             ON DUPLICATE KEY UPDATE state = VALUES(state), payload = VALUES(payload)",
            [
                ':id' => $tgId,
                ':s'  => $state,
                ':p'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /* ============================================================
     *  TELEGRAM HTTP
     * ============================================================ */

    /** @param array<int,array<int,array<string,string>>>|null $keyboard */
    public static function sendMessage(int $chatId, string $text, ?array $keyboard = null): ?string
    {
        $token = (string) getenv('TG_BOT_TOKEN');
        if ($token === '') return null;
        $url = self::TG_API . $token . '/sendMessage';
        $params = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ];
        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }
        return self::httpPost($url, $params);
    }

    private static function answerCallback(string $callbackQueryId): void
    {
        $token = (string) getenv('TG_BOT_TOKEN');
        if ($token === '' || $callbackQueryId === '') return;
        self::httpPost(self::TG_API . $token . '/answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
        ]);
    }

    /** @param array<int,array<int,array<string,string>>> $rows */
    private static function keyboard(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    /** @param array<string,mixed> $params */
    private static function httpPost(string $url, array $params): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log('[TG_HTTP] ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        return is_string($resp) ? $resp : null;
    }

    private static function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) { curl_close($ch); return null; }
        curl_close($ch);
        return is_string($resp) ? $resp : null;
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    /** @return array{humo:string,visa:string,holder:string} */
    private static function loadCards(): array
    {
        $rows = Database::select(
            "SELECT `key`, `value` FROM system_settings
             WHERE `key` IN ('humo_card','visa_card','card_holder')"
        );
        $map = [];
        foreach ($rows as $r) $map[$r['key']] = (string) $r['value'];
        return [
            'humo'   => $map['humo_card']   ?? '',
            'visa'   => $map['visa_card']   ?? '',
            'holder' => $map['card_holder'] ?? '',
        ];
    }

    private static function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 4) return $phone;
        return substr($phone, 0, 4) . str_repeat('*', $len - 6) . substr($phone, -2);
    }

    private static function escapeMd(string $text): string
    {
        // Markdown (legacy) — escape *, _, `, [
        return str_replace(['_', '*', '`', '['], ['\_', '\*', '\`', '\['], $text);
    }
}
