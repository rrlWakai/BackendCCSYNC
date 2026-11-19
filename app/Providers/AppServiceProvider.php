<?php

namespace App\Providers;

use App\Services\Scheduling\Contracts\SchedulingConfigurationProvider;
use App\Services\Scheduling\DatabaseSchedulingConfigurationProvider;
use App\Services\Scheduling\SchedulerClient;
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
        $this->app->singleton(
            SchedulingConfigurationProvider::class,
            DatabaseSchedulingConfigurationProvider::class,
        );

        $this->app->singleton(SchedulerClient::class, function (): SchedulerClient {
            $url = config('scheduler.url');
            $apiKey = config('scheduler.api_key');

            return new SchedulerClient(
                url: is_string($url) ? $url : null,
                apiKey: is_string($apiKey) ? $apiKey : null,
                connectTimeout: (int) config('scheduler.connect_timeout', 5),
                timeout: (int) config('scheduler.timeout', 30),
            );
        });
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

        RateLimiter::for('room-monitoring-scan', function (Request $request): Limit {
            $key = $request->user()?->id !== null
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return Limit::perMinute(12)->by($key);
        });
    }
}
