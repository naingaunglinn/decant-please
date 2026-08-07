<?php

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\PaymentProofUploaded;
use App\Listeners\NotifyAdminOfNewOrder;
use App\Listeners\NotifyAdminOfPaymentProof;
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
        $this->app->singleton(\App\Support\TenantContext::class);
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

        // Named limiters so each endpoint gets its own per-IP bucket. Inline
        // `throttle:n,1` keys guests by ip alone (no path), so catalog browsing
        // was silently eating the checkout allowance.
        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(120)->by('catalog|'.$request->ip()));
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by('checkout|'.$request->ip()));
        RateLimiter::for('tracking', fn (Request $request) => Limit::perMinute(20)->by('tracking|'.$request->ip()));
        RateLimiter::for('cancel', fn (Request $request) => Limit::perMinute(10)->by('cancel|'.$request->ip()));
        RateLimiter::for('payment-proof', fn (Request $request) => Limit::perMinute(10)->by('payment-proof|'.$request->ip()));
        RateLimiter::for('promo', fn (Request $request) => Limit::perMinute(10)->by('promo|'.$request->ip()));

        // Admin Telegram alerts. Wired explicitly, and necessarily so: event
        // auto-discovery is off (bootstrap/app.php, #52), so a listener that
        // isn't registered here silently never runs.
        Event::listen(OrderPlaced::class, NotifyAdminOfNewOrder::class);
        Event::listen(PaymentProofUploaded::class, NotifyAdminOfPaymentProof::class);
    }
}
