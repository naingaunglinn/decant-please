<?php

namespace App\Support\PhoneVerification;

/**
 * Delivers a sign-up code to a phone (step 44b). One method, so the owner's
 * chosen provider (SMS, Telegram Gateway, …) is one small class — picked by
 * PHONE_VERIFICATION_DRIVER in PhoneVerification::sender().
 */
interface CodeSender
{
    /** $phone is normalized (+959…). Throws when the provider refuses. */
    public function send(string $phone, string $code): void;
}
