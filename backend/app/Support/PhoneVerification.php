<?php

namespace App\Support;

use App\Models\User;
use App\Support\PhoneVerification\CodeSender;
use App\Support\PhoneVerification\LogCodeSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The self-serve sign-up's phone check (step 44b): a 6-digit code, stored hashed
 * in the cache for 10 minutes, 5 tries, sends throttled per phone and per IP.
 * Platform-level on purpose — no shop exists yet, so no shop is in any key.
 *
 * Known limit: a code belongs to the phone, not the browser, so a stranger who
 * types the seller's number can replace the seller's code (and spend its three
 * sends). A nuisance, never a bypass — the stranger can't read the new code.
 *
 * Fails closed (P2): with no working sender, sign-up is off — never "verification
 * skipped". The `log` driver counts only in local and testing.
 */
class PhoneVerification
{
    public const CODE_TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    /** Sign-up is on only while a code can actually reach a phone. */
    public static function enabled(): bool
    {
        return self::sender() !== null;
    }

    public static function sender(): ?CodeSender
    {
        return match (config('services.phone_verification.driver')) {
            'log' => app()->environment('local', 'testing') ? new LogCodeSender : null,
            // the owner's provider driver lands here (an owner decision, queue row 11c)
            default => null,
        };
    }

    /**
     * A Myanmar mobile number in one spelling, `+959…`, whether typed as
     * `09 7xx…`, `959…` or `+95 9…` — so one SIM is one key and one account.
     * Null when it isn't one.
     */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', (string) $input);
        $digits = preg_replace('/^(?:\+?95)?0?(?=9)/', '', $digits); // 09…, 959…, +95 9…, +95 09…

        return preg_match('/^9\d{7,9}$/', $digits) === 1 ? '+95'.$digits : null;
    }

    /** Sends a fresh code (replacing any earlier one). Throws ValidationException keyed `phone`. */
    public static function send(string $phone, string $ip): void
    {
        $sender = self::sender();

        if ($sender === null) {
            throw ValidationException::withMessages(['phone' => 'Sign-up is closed right now. · ယခု အကောင့်ဖွင့်၍ မရသေးပါ။']);
        }

        foreach (['phone:'.sha1($phone) => [3, 900], 'ip:'.$ip => [10, 3600]] as $key => [$max, $decay]) {
            $key = 'phone-otp-send:'.$key;

            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                throw ValidationException::withMessages(['phone' => "Too many codes asked for. Try again in {$minutes} min. · ကုဒ်တောင်းတာ များလွန်းပါသည်။ {$minutes} မိနစ်နေမှ ပြန်ကြိုးစားပါ။"]);
            }
        }

        RateLimiter::hit('phone-otp-send:phone:'.sha1($phone), 900);
        RateLimiter::hit('phone-otp-send:ip:'.$ip, 3600);

        // after the throttle, so it can't be looped to list whose phones have accounts
        if (User::query()->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'This phone number already has an account. · ဤဖုန်းနံပါတ်ဖြင့် အကောင့်ဖွင့်ပြီးသား ဖြစ်ပါသည်။']);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        Cache::put(self::key($phone), [
            'hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(self::CODE_TTL_SECONDS)->getTimestamp(),
        ], self::CODE_TTL_SECONDS);
        Cache::put(self::attemptsKey($phone), 0, self::CODE_TTL_SECONDS);

        try {
            $sender->send($phone, $code);
        } catch (Throwable $e) {
            Cache::forget(self::key($phone));
            Log::error('Sign-up code could not be sent', ['error' => $e->getMessage()]);

            throw ValidationException::withMessages(['phone' => 'The code could not be sent. Please try again. · ကုဒ်ပို့၍ မရပါ။ ထပ်ကြိုးစားပါ။']);
        }
    }

    /**
     * Checks the code without using it up — the sign-up form may still fail on
     * another field. Every try counts, counted atomically before the hash is
     * compared (parallel guesses can't share one count); after the fifth the code
     * is dropped. Throws ValidationException keyed `code`. consume() after the
     * account exists.
     */
    public static function check(string $phone, string $code): void
    {
        $entry = Cache::get(self::key($phone));

        if (! is_array($entry) || $entry['expires_at'] <= now()->getTimestamp()) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Ask for a new one. · ကုဒ် သက်တမ်းကုန်သွားပါပြီ။ ကုဒ်အသစ် တောင်းပါ။']);
        }

        // add() first: some stores' increment() does nothing on a missing key
        Cache::add(self::attemptsKey($phone), 0, $entry['expires_at'] - now()->getTimestamp());
        $tries = (int) Cache::increment(self::attemptsKey($phone));

        if ($tries > self::MAX_ATTEMPTS) {
            self::consume($phone);

            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Ask for a new one. · ကုဒ်မှားတာ များလွန်းပါသည်။ ကုဒ်အသစ် တောင်းပါ။']);
        }

        if (Hash::check($code, $entry['hash'])) {
            return;
        }

        if ($tries === self::MAX_ATTEMPTS) {
            self::consume($phone);

            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Ask for a new one. · ကုဒ်မှားတာ များလွန်းပါသည်။ ကုဒ်အသစ် တောင်းပါ။']);
        }

        throw ValidationException::withMessages(['code' => 'Wrong code. · ကုဒ် မှားနေပါသည်။']);
    }

    /** A code works once. */
    public static function consume(string $phone): void
    {
        Cache::forget(self::key($phone));
        Cache::forget(self::attemptsKey($phone));
    }

    private static function key(string $phone): string
    {
        return 'phone-otp:'.sha1($phone);
    }

    private static function attemptsKey(string $phone): string
    {
        return 'phone-otp-tries:'.sha1($phone);
    }
}
