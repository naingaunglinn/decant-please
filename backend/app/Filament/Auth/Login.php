<?php

namespace App\Filament\Auth;

use App\Support\PhoneVerification;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Filament's login, with the "sign up" link shown only while sign-up is on
 * (step 44b) — the route always exists, but 404s when no code can be sent.
 */
class Login extends BaseLogin
{
    public function getSubheading(): string|Htmlable|null
    {
        return PhoneVerification::enabled() ? parent::getSubheading() : null;
    }
}
