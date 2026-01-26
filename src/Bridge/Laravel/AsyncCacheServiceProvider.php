<?php

namespace Fyennyi\AsyncCache\Bridge\Laravel;

use Fyennyi\AsyncCache\AsyncCacheManager;
use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Service Provider for Laravel integration
 */
class AsyncCacheServiceProvider extends ServiceProvider
{
    /**
     * Register services in the container
     */
    public function register() : void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/async-cache.php', 'async-cache');

        $this->app->singleton(AsyncCacheManager::class, function ($app) {
            // Laravel automatically provides implementations for CacheInterface and LoggerInterface
            return new AsyncCacheManager(
                cache_adapter: $app->make(CacheInterface::class),
                rate_limiter_type: RateLimiterType::from(config('async-cache.rate_limiter_type', 'auto')),
                logger: $app->make(LoggerInterface::class)
            );
        });
    }

    /**
     * Bootstrap services
     */
    public function boot() : void
    {
        $this->publishes([
            __DIR__ . '/../../../config/async-cache.php' => config_path('async-cache.php'),
        ], 'config');
    }
}
