<?php

namespace App\Providers;

use App\Services\YouTube\DemoGateway;
use App\Services\YouTube\Gateway;
use App\Services\YouTube\GoogleGateway;
use App\Support\DatabasePolicy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Gateway::class, fn () => match (config('youtube.mode')) {
            'demo' => new DemoGateway,
            'live' => new GoogleGateway,
            default => throw new \RuntimeException('YOUTUBE_MODE must be live or demo'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DatabasePolicy::assertConfiguration(
            $this->app['db']->connection()->getConfig(),
            $this->app->environment('testing'),
            $this->app->environment(),
        );
    }
}
