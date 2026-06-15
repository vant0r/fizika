<?php
declare(strict_types=1);

namespace App\Sms;

use RuntimeException;

/**
 * EskizSmsProvider — Eskiz.uz SMS gateway client.
 *
 * Authentication is JWT-based: an email/password pair is exchanged for a
 * bearer token at /auth/login, then included on each /message/sms/send call.
 * Tokens are cached in-process for the lifetime of the request.
 *
 * Configuration (env):
 *   SMS_PROVIDER=eskiz
 *   SMS_ESKIZ_EMAIL=user@example.com
 *   SMS_ESKIZ_PASSWORD=xxx
 *   SMS_FROM=4546                  (default Eskiz alpha sender)
 *
 * Templates must be pre-approved by Eskiz support; the bundled OTP message
 * "Physics Cert: kirish kodingiz {code} ..." should be registered before
 * production use.
 */
final class EskizSmsProvider implements SmsProvider
{
    private const API_BASE = 'https://notify.eskiz.uz/api';
    private const TIMEOUT  = 15;

    private string $email;
    private string $password;
    private string $from;
    private ?string $token = null;

    public function __construct(string $email, string $password, string $from = '4546')
    {
        $this->email    = $email;
        $this->password = $password;
        $this->from     = $from;
    }

    public function send(string $phone, string $message): bool
    {
        try {
            if ($this->token === null) {
                $this->token = $this->authenticate();
            }
            // Eskiz expects national format: 998901234567 (no leading +)
            $to = ltrim($phone, '+');
            if (!str_starts_with($to, '998')) {
                $to = '998' . $to;
            }
            $resp = $this->httpPost('/message/sms/send', [
                'mobile_phone' => $to,
                'message'      => $message,
                'from'         => $this->from,
            ], auth: true);
            $j = json_decode($resp ?? '', true);
            $status = is_array($j) ? (string) ($j['status'] ?? '') : '';
            // 'waiting' = queued for delivery, 'success' = accepted
            return in_array($status, ['waiting', 'success'], true);
        } catch (\Throwable $e) {
            error_log('[Eskiz] send failed: ' . $e->getMessage());
            return false;
        }
    }

    private function authenticate(): string
    {
        if ($this->email === '' || $this->password === '') {
            throw new RuntimeException('SMS_ESKIZ_EMAIL / SMS_ESKIZ_PASSWORD not configured');
        }
        $resp = $this->httpPost('/auth/login', [
            'email'    => $this->email,
            'password' => $this->password,
        ], auth: false);
        $j = json_decode($resp ?? '', true);
        $tok = is_array($j) ? ($j['data']['token'] ?? null) : null;
        if (!is_string($tok) || $tok === '') {
            throw new RuntimeException('Eskiz: authentication failed');
        }
        return $tok;
    }

    /** @param array<string,string> $params */
    private function httpPost(string $path, array $params, bool $auth): ?string
    {
        $ch = curl_init(self::API_BASE . $path);
        $headers = ['Accept: application/json'];
        if ($auth && $this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            error_log('[Eskiz] HTTP error: ' . $err);
            return null;
        }
        return is_string($resp) ? $resp : null;
    }
}
