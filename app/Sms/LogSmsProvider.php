<?php
declare(strict_types=1);

namespace App\Sms;

/**
 * LogSmsProvider — writes SMS payload to PHP error_log instead of dispatching.
 * Useful during development to read OTP codes from server logs.
 *
 * SECURITY: in production this would leak OTP codes to logs.
 * SmsFactory refuses to instantiate this in APP_ENV=production unless
 * SMS_PROVIDER_FORCE_LOG=1 is also set (escape hatch for staging mirrors).
 */
final class LogSmsProvider implements SmsProvider
{
    public function send(string $phone, string $message): bool
    {
        // Single-line, easy to grep
        error_log(sprintf(
            '[SMS][LogProvider] to=%s len=%d body=%s',
            $phone,
            strlen($message),
            preg_replace('/\s+/', ' ', $message)
        ));
        return true;
    }
}
