<?php

namespace App\Providers;

use App\Broadcasting\SingleLineLogBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
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
        $this->app->make(BroadcastManager::class)->extend(
            'single-line-log',
            fn ($app, array $config): SingleLineLogBroadcaster => new SingleLineLogBroadcaster(
                $app->make(LoggerInterface::class),
            ),
        );
    }
}
