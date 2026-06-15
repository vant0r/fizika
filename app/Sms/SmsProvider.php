<?php
declare(strict_types=1);

namespace App\Sms;

interface SmsProvider
{
    /**
     * Sends an SMS message. Returns true on success, false on transient failure.
     * Implementations MUST log their own errors.
     *
     *  $phone   E.164 (e.g. "+998901234567")
     *  $message Plain text
     */
    public function send(string $phone, string $message): bool;
}
