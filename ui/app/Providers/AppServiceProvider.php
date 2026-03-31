<?php

namespace App\Providers;

use App\Broadcasting\SingleLineLogBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

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
        RateLimiter::for('webhook-page', function (Request $request): Limit {
            return Limit::perMinute(
                max(1, (int) config('webhooks.page_rate_limit_per_minute', 60)),
            )->by($request->ip());
        });

        RateLimiter::for('webhook-delivery', function (Request $request): Limit {
            return Limit::perMinute(
                max(1, (int) config('webhooks.delivery_rate_limit_per_minute', 240)),
            )->by($request->ip().'|'.$request->route('token'));
        });

        $this->app->make(BroadcastManager::class)->extend(
            'single-line-log',
            fn ($app, array $config): SingleLineLogBroadcaster => new SingleLineLogBroadcaster(
                $app->make(LoggerInterface::class),
            ),
        );
    }
}
