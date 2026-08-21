<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The studio's own panel (/studio) — the super-admin home that is not inside any
 * shop. No ->tenant(): everything here is cross-shop by nature, starting with the
 * shop registry (App\Filament\Studio\Resources); the 25b features that belong to
 * no single shop (all-shops dashboard, per-tenant Telegram, theming) land here
 * too. Entry is gated by User::canAccessPanel — is_studio only; a future shop
 * owner's login reaches only the tenant panel (/admin/{shop}).
 *
 * Same session guard as the admin panel, so one login serves both; the emerald
 * primary (vs the shop panel's amber) is the "which panel am I in?" cue.
 */
class StudioPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('studio')
            ->path('studio')
            ->login()
            ->brandName('Decant Please! — Studio')
            ->colors([
                'primary' => Color::Emerald,
            ])
            // Step 34 PR-3 — one item didn't need groups; three do. Registry holds the
            // shop registry; Operations holds the impersonation audit log. (Platform /
            // Stats / support-search are §6 non-goals — no empty groups.)
            ->navigationGroups([
                'Registry',
                'Operations',
            ])
            ->discoverResources(in: app_path('Filament/Studio/Resources'), for: 'App\Filament\Studio\Resources')
            // Shield's Role management UI lives here, on /studio ONLY (Step 34 /
            // ADR-0003 amendment). The /admin panel gets no Shield UI; its
            // resources are still enforced by the generated model-level policies.
            ->plugin(FilamentShieldPlugin::make())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
