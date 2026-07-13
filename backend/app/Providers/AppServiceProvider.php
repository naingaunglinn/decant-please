<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
        //
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
        RateLimiter::for('promo', fn (Request $request) => Limit::perMinute(10)->by('promo|'.$request->ip()));
    }
}
