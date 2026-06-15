<?php
declare(strict_types=1);

namespace App\Sms;

use RuntimeException;

/**
 * SmsFactory — picks the active SMS provider based on environment.
 *
 *  SMS_PROVIDER=eskiz  → Eskiz.uz
 *  SMS_PROVIDER=log    → LogSmsProvider (DEV only — refuses prod unless forced)
 *  unset/empty         → log (with the same prod refusal rule)
 *
 * Tests can call SmsFactory::override(...) to inject a mock and
 * SmsFactory::reset() to release it.
 */
final class SmsFactory
{
    private static ?SmsProvider $current = null;
    private static bool $overridden = false;

    public static function current(): SmsProvider
    {
        if (self::$current !== null) return self::$current;

        $provider = strtolower((string) (getenv('SMS_PROVIDER') ?: 'log'));
        $env      = (string) (getenv('APP_ENV') ?: 'production');

        switch ($provider) {
            case 'eskiz':
                self::$current = new EskizSmsProvider(
                    (string) getenv('SMS_ESKIZ_EMAIL'),
                    (string) getenv('SMS_ESKIZ_PASSWORD'),
                    (string) (getenv('SMS_FROM') ?: '4546')
                );
                break;

            case 'log':
                if ($env === 'production'
                    && (string) getenv('SMS_PROVIDER_FORCE_LOG') !== '1'
                ) {
                    throw new RuntimeException(
                        'SMS provider not configured. Set SMS_PROVIDER=eskiz with credentials, '
                        . 'or SMS_PROVIDER_FORCE_LOG=1 to use log provider in production.'
                    );
                }
                self::$current = new LogSmsProvider();
                break;

            default:
                throw new RuntimeException("Unknown SMS provider: $provider");
        }

        return self::$current;
    }

    /** Test seam: inject a mock provider. */
    public static function override(SmsProvider $p): void
    {
        self::$current    = $p;
        self::$overridden = true;
    }

    /** Test seam: clear cached provider so current() re-reads env. */
    public static function reset(): void
    {
        self::$current    = null;
        self::$overridden = false;
    }
}
