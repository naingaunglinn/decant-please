<?php

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\PaymentProofUploaded;
use App\Listeners\NotifyAdminOfNewOrder;
use App\Listeners\NotifyAdminOfPaymentProof;
use App\Listeners\SyncTenantContextFromFilament;
use App\Support\TenantContext;
use Filament\Events\TenantSet;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Read by BelongsToShop's global scope and creating hook. Registered as a
        // plain singleton: FPM builds a fresh container per request, so this is
        // already per-request (no cross-request leak), and unlike a scoped binding it
        // survives the container's scoped-instance flush that Livewire/HTTP test
        // helpers trigger mid-test — where nothing re-runs the resolver middleware.
        // If this app ever moves to Octane (persistent container), switch to scoped()
        // and reset it in an Octane RequestReceived listener.
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface N+1 queries in dev/test; never throw in front of the decanter.
        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Named limiters so each endpoint gets its own bucket, keyed shop + IP.
        // Both components are load-bearing: the endpoint prefix because inline
        // `throttle:n,1` keys guests by ip alone (no path), so catalog browsing
        // was silently eating the checkout allowance — and the shop (step 32)
        // because Myanmar's mobile carriers NAT many customers behind one IP,
        // so a per-IP-only bucket lets one shop's traffic spend every shop's
        // budget. ResolveTenant has always run by the time a limiter resolves
        // (it sits on the /api/v1/{shop} group, outside the route throttles).
        $perShopIp = fn (string $bucket, Request $request): string => $bucket.'|'.app(TenantContext::class)->slug().'|'.$request->ip();

        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(120)->by($perShopIp('catalog', $request)));
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($perShopIp('checkout', $request)));
        RateLimiter::for('tracking', fn (Request $request) => Limit::perMinute(20)->by($perShopIp('tracking', $request)));
        RateLimiter::for('cancel', fn (Request $request) => Limit::perMinute(10)->by($perShopIp('cancel', $request)));
        RateLimiter::for('payment-proof', fn (Request $request) => Limit::perMinute(10)->by($perShopIp('payment-proof', $request)));
        RateLimiter::for('promo', fn (Request $request) => Limit::perMinute(10)->by($perShopIp('promo', $request)));

        // Admin Telegram alerts. Wired explicitly, and necessarily so: event
        // auto-discovery is off (bootstrap/app.php, #52), so a listener that
        // isn't registered here silently never runs.
        Event::listen(OrderPlaced::class, NotifyAdminOfNewOrder::class);
        Event::listen(PaymentProofUploaded::class, NotifyAdminOfPaymentProof::class);

        // Multi-tenancy Step 25a: mirror Filament's resolved panel tenant into our
        // own TenantContext so the app scope stays the single source of truth.
        Event::listen(TenantSet::class, SyncTenantContextFromFilament::class);
    }
}
