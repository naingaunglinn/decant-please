<?php

namespace App\Support\PhoneVerification;

use Illuminate\Support\Facades\Log;

/**
 * Writes the code to the application log — local development and tests only.
 * PhoneVerification::sender() never returns it in production: a code nobody
 * receives would be sign-up without verification (P2 fails closed).
 */
class LogCodeSender implements CodeSender
{
    public function send(string $phone, string $code): void
    {
        Log::info('Sign-up verification code', ['phone' => $phone, 'code' => $code]);
    }
}
