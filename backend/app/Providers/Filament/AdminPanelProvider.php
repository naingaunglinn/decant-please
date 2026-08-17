<?php

namespace App\Providers\Filament;

use App\Http\Controllers\OrderInvoiceController;
use App\Http\Controllers\PaymentProofViewController;
use App\Models\Shop;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Livewire's temporary-upload disk defaults to the app's default filesystem
        // disk, which is s3/R2 in production. That makes the browser upload the file
        // straight to R2 via a presigned PUT — which R2 blocks on CORS. Pin temp
        // uploads to the local disk so they POST to Livewire's own same-origin
        // endpoint; Filament then moves the finished file to the media disk (R2)
        // server-side, no CORS involved. Safe on the single Heroku web dyno: the temp
        // file is short-lived and the upload + submit hit the same dyno. If the web
        // process is ever scaled past one dyno, revisit (shared temp storage or R2 +
        // a bucket CORS policy). See DEPLOY.md "Admin image uploads".
        config(['livewire.temporary_file_upload.disk' => 'local']);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Multi-tenancy Step 25a: the panel is now tenant-aware. Routes become
            // /admin/{shop}/…; the shop is resolved from the slug, the switcher is
            // rendered from the user's getTenants(), and IdentifyTenant enforces
            // canAccessTenant. Our SyncTenantContextFromFilament listener mirrors the
            // resolved tenant into TenantContext (AppServiceProvider).
            ->tenant(Shop::class, slugAttribute: 'slug')
            // The topbar wears the CURRENT shop's name — this panel belongs to
            // whichever shop you're standing in, not to the platform. Tenant-less
            // pages (login) fall back to the platform name.
            ->brandName(fn (): string => Filament::getTenant()?->name ?? 'Decant Please!')
            ->colors([
                'primary' => Color::Amber,
                'blue' => Color::Blue,
                'rose' => Color::Rose,
            ])
            ->navigationGroups([
                'Catalog',
                'Sales',
                'Finance',
                'Settings',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // The way back up: studio users get a user-menu link to their own
            // panel (/studio — shop registry and, later, the cross-shop views).
            // Owners never see it; canAccessPanel would 403 them there anyway.
            ->userMenuItems([
                MenuItem::make()
                    ->label('Studio')
                    ->icon(Heroicon::OutlinedBuildingLibrary)
                    ->url(fn (): string => Filament::getPanel('studio')->getUrl())
                    ->visible(fn (): bool => (bool) auth()->user()?->is_studio),
            ])
            // authenticatedTenantRoutes(), NOT authenticatedRoutes() or routes():
            // routes() closures register alongside login/password-reset, outside the
            // panel's auth middleware, and authenticatedRoutes() closures sit inside
            // auth but OUTSIDE the {tenant} group (vendor routes/web.php) — there
            // IdentifyTenant never runs, TenantContext stays unset, and the {order}
            // binding throws TenantNotSetException. authenticatedTenantRoutes()
            // closures get both: the auth guard (an invoice or proof must never
            // render for a logged-out visitor) and the /admin/{tenant} prefix, so
            // the binding resolves under the tenant scope (bootstrap/app.php orders
            // IdentifyTenant before SubstituteBindings). Named
            // filament.admin.orders.invoice and filament.admin.orders.payment-proof.
            ->authenticatedTenantRoutes(function (): void {
                Route::get('/orders/{order}/invoice', OrderInvoiceController::class)
                    ->name('orders.invoice');
                Route::get('/orders/{order}/payment-proof', PaymentProofViewController::class)
                    ->name('orders.payment-proof');
            })
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
