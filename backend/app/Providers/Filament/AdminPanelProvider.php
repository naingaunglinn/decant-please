<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ->brandName('Decant Please!')
            ->colors([
                'primary' => Color::Amber,
                'blue' => Color::Blue,
                'rose' => Color::Rose,
            ])
            ->navigationGroups([
                'Catalog',
                'Sales',
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
