<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
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
 * Same session guard as the admin panel, so one login serves both. Step 34 PR-3b
 * makes Studio a deliberate dark "control room" register: the pine-ramp primary
 * (§3 design tokens — pine's hue raised to a legible teal-green, vs the shop panel's
 * amber) on a forced-dark surface is the "which panel am I in?" cue. Dark is forced
 * per-panel WITHOUT touching the origin-global localStorage 'theme' key, so the
 * /admin panel's own light/dark setting is left exactly as it was.
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
            // Step 34 PR-3b — the Studio primary is the pine ramp (§3 design tokens):
            // pine's hue (#013E37) raised to a legible teal-green on the dark surface.
            // These 11 hexes are the SAME ramp mirrored in the repo-root
            // design-tokens.json and frontend globals.css; change all three together.
            // Registered as an explicit hex array (not Color::hex) so a reviewer diffs
            // identical values across the mirrors, and the panel never has to read a
            // repo-root file the Heroku backend slug does not ship.
            ->colors([
                'primary' => [
                    50 => '#F1FAF8',
                    100 => '#E0F4F0',
                    200 => '#C3E9E2',
                    300 => '#98DACE',
                    400 => '#5EC3B4',
                    500 => '#2FAF9F',
                    600 => '#009485',
                    700 => '#00796C',
                    800 => '#006258',
                    900 => '#0F5149',
                    950 => '#04302A',
                ],
            ])
            // Deliberate dark "control room" register (§5). defaultThemeMode states the
            // intent; the HEAD_START hook then guarantees dark before first paint
            // WITHOUT writing the origin-global localStorage 'theme' key — that key is
            // shared with /admin, so darkMode(isForced:) would drag the Admin panel dark
            // too (and flash it). This adds the class and never persists, so /admin is
            // untouched. loadDarkMode() only ever ADDS the class, so it can't undo this.
            ->defaultThemeMode(ThemeMode::Dark)
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): string => '<script>document.documentElement.classList.add(\'dark\')</script>',
            )
            // Studio-scoped CSS (this hook lives only on the studio panel): hide the
            // light/dark switch — Studio is dark, full stop — and restyle the slug chip
            // to §3's hairline "vial label" pill (frontend/AGENTS.md UI rule 2) instead
            // of a filled badge. The .dp-vial-label scope (set on the registry cell and
            // the detail entry) keeps every other badge in the panel untouched; the
            // colors read the registered pine primary vars so they track the ramp.
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => <<<'HTML'
                    <style>
                        .fi-theme-switcher { display: none !important; }

                        .dp-vial-label.fi-badge,
                        .dp-vial-label .fi-badge {
                            background-color: color-mix(in oklch, var(--primary-500) 10%, transparent) !important;
                            border: 1px solid color-mix(in oklch, var(--primary-400) 50%, transparent) !important;
                            color: var(--primary-300) !important;
                            box-shadow: none !important;
                            border-radius: 9999px !important;
                            font-weight: 500;
                            letter-spacing: 0.01em;
                        }
                    </style>
                    HTML,
            )
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
