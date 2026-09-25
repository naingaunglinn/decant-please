<?php

namespace App\Support;

use App\Enums\ShopStatus;
use App\Models\Shop;
use App\Models\ShopDomain;
use App\Models\User;
use App\Templates\Templates;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Registering a shop, in one place (step 44a). The Studio's "Register a shop"
 * action and the self-serve sign-up (44b) both call register(), so neither
 * re-derives what a new shop needs (AGENTS.md P4):
 *
 *   1. the shop row;
 *   2. its category (shop_settings.template);
 *   3. its owner login, when given — a non-studio user attached through
 *      shop_user with the shop_owner role: full control of this shop, nothing else;
 *   4. its platform address, `{slug}.{STOREFRONT_BASE_DOMAIN}` — primary and
 *      verified, because the wildcard DNS + Vercel wildcard already reach it;
 *   5. its national delivery geography, all inactive at fee 0.
 *
 * 1–4 commit together: a shop whose owner or address failed half-way would be a
 * login that guards nothing, or a shop without a way in. The geography seeds after
 * the commit, as it always has — it is idempotent, so a retry is safe.
 *
 * Platform-level on purpose: shops, users, shop_user and shop_domains carry no
 * BelongsToShop, and the tenant-owned writes (settings, geography) run under the
 * new shop's own context inside Templates::assignToShop / NationalGeography::seed.
 * No withoutTenancy().
 */
class ShopRegistration
{
    /**
     * Never a shop's subdomain: the platform's own hosts, and words that would
     * pass for the platform. Adding a platform subdomain means adding it here first.
     */
    public const RESERVED_SLUGS = [
        'about', 'account', 'admin', 'api', 'app', 'assets', 'auth', 'autoconfig',
        'autodiscover', 'billing', 'blog', 'cdn', 'cornerarea', 'dashboard', 'dev',
        'docs', 'email', 'files', 'ftp', 'help', 'images', 'img', 'login', 'mail',
        'media', 'ns1', 'ns2', 'pay', 'register', 'root', 'secure', 'shop', 'shops',
        'signup', 'smtp', 'staging', 'static', 'status', 'storefront', 'studio',
        'support', 'test', 'webmail', 'www',
    ];

    /**
     * The slug is a URL segment (/admin/{slug}, /api/v1/{slug}) and, from here on,
     * a public hostname — so it must be a plain DNS label: lowercase letters,
     * digits and single dashes, 3–40 characters, no dash at either end. Rejecting
     * `--` also rules out `xn--` punycode look-alikes.
     *
     * @return array<int, mixed>
     */
    public static function slugRules(?Shop $ignore = null): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:40',
            'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (in_array($value, self::RESERVED_SLUGS, true)) {
                    $fail('This name is reserved. Please choose another.');
                }
            },
            'unique:shops,slug'.($ignore ? ','.$ignore->getKey() : ''),
            // the address this slug would get must be free too — a custom domain
            // the Studio mapped by hand may already own it
            function (string $attribute, mixed $value, Closure $fail): void {
                $host = is_string($value) ? self::platformHost($value) : null;

                if ($host !== null && ShopDomain::query()->where('host', $host)->exists()) {
                    $fail('This address is already taken.');
                }
            },
        ];
    }

    /**
     * @param  array{name: string, email: string, password: string}|null  $owner
     */
    public static function register(
        string $name,
        string $slug,
        string $template = Templates::DEFAULT,
        ShopStatus $status = ShopStatus::Onboarding,
        ?array $owner = null,
    ): Shop {
        Validator::make(
            ['slug' => $slug, 'email' => $owner['email'] ?? null],
            [
                'slug' => self::slugRules(),
                // users are platform-wide, so a plain unique across the table
                'email' => $owner === null ? [] : ['required', 'email', 'max:255', 'unique:users,email'],
            ],
        )->validate();

        $host = self::platformHost($slug);

        try {
            $shop = DB::transaction(fn (): Shop => self::create($name, $slug, $template, $status, $owner, $host));
        } catch (UniqueConstraintViolationException) {
            // lost a race with another registration between the checks above and
            // the insert — the same answer the checks would have given, not a 500
            throw ValidationException::withMessages([
                'slug' => 'This name or email was just taken. Please try again.',
            ]);
        }

        // the address row joined the CORS allowlist inside the transaction; bust
        // again now it is committed, so no request re-cached the old list meanwhile
        ShopDomain::forgetCorsOrigins();

        NationalGeography::seed($shop);

        return $shop;
    }

    /**
     * @param  array{name: string, email: string, password: string}|null  $owner
     */
    private static function create(string $name, string $slug, string $template, ShopStatus $status, ?array $owner, ?string $host): Shop
    {
        $shop = Shop::create(['name' => $name, 'slug' => $slug, 'status' => $status]);
        Templates::assignToShop($shop, $template);

        if ($owner !== null) {
            $user = User::create([
                'name' => $owner['name'],
                'email' => $owner['email'],
                'password' => $owner['password'], // hashed cast
            ]);
            $user->shops()->attach($shop);
            // The capability role (Shield). WHICH shop stays the membership
            // above + canAccessTenant/BelongsToShop. Never studio_admin.
            $user->assignRole('shop_owner');
        }

        if ($host !== null) {
            $shop->domains()->create([
                'host' => $host,
                'is_primary' => true,
                'verified_at' => now(),
            ]);
        }

        return $shop;
    }

    /** `{slug}.{base}`, or null while STOREFRONT_BASE_DOMAIN is blank (no automatic address). */
    public static function platformHost(string $slug): ?string
    {
        $base = ltrim(ShopDomain::normalizeHost((string) config('app.storefront_base_domain')), '.');

        return $base === '' ? null : ShopDomain::normalizeHost("{$slug}.{$base}");
    }
}
