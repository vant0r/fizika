<?php
declare(strict_types=1);

namespace App\Queue\Handlers;

use App\Bot\TelegramWebhook;
use App\Queue\JobHandler;
use RuntimeException;

/**
 * TelegramSendHandler — handles 'tg_send' jobs.
 *
 * Payload schema:
 *   {
 *     "chat_id": int,        // required
 *     "text":    string,     // required
 *     "parse_mode": ?string  // optional (defaults to Markdown via TelegramWebhook)
 *   }
 *
 * Throws on transient failure so the queue retries with backoff.
 */
final class TelegramSendHandler implements JobHandler
{
    public function handle(array $payload, array $meta): void
    {
        $chatId = (int) ($payload['chat_id'] ?? 0);
        $text   = (string) ($payload['text'] ?? '');

        if ($chatId <= 0) {
            throw new RuntimeException('payload.chat_id missing or invalid');
        }
        if ($text === '') {
            throw new RuntimeException('payload.text missing');
        }
        if (mb_strlen($text) > 4000) {
            // Telegram caps at 4096; trim with marker
            $text = mb_substr($text, 0, 3995) . '…';
        }

        $resp = TelegramWebhook::sendMessage($chatId, $text);
        if ($resp === null) {
            throw new RuntimeException('TG sendMessage returned null');
        }
        $j = json_decode((string) $resp, true);
        if (!is_array($j) || empty($j['ok'])) {
            $code = is_array($j) ? (int) ($j['error_code'] ?? 0) : 0;
            $desc = is_array($j) ? (string) ($j['description'] ?? '') : '';
            // 4xx ≠ retry: bad chat_id, blocked by user, etc.
            //   But we still let the queue retry up to max_attempts —
            //   the operator can examine last_error and cancel manually.
            throw new RuntimeException("TG error $code: $desc");
        }
    }
}
