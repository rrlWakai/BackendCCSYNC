<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('attendance-qr-scan', function (Request $request): Limit {
            $key = $request->user()?->id !== null
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return Limit::perMinute(
                max(1, (int) config('attendance.qr_scan_rate_limit_per_minute', 12)),
            )->by($key);
        });

        RateLimiter::for('attendance-qr-manage', function (Request $request): Limit {
            return Limit::perMinute(30)->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }
}
