<?php

/*
 *
 *     _                          ____           _            ____  _   _ ____
 *    / \   ___ _   _ _ __   ___ / ___|__ _  ___| |__   ___  |  _ \| | | |  _ \
 *   / _ \ / __| | | | '_ \ / __| |   / _` |/ __| '_ \ / _ \ | |_) | |_| | |_) |
 *  / ___ \\__ \ |_| | | | | (__| |__| (_| | (__| | | |  __/ |  __/|  _  |  __/
 * /_/   \_\___/\__, |_| |_|\___|\____\__,_|\___|_| |_|\___| |_|   |_| |_|_|
 *              |___/
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author Serhii Cherneha
 * @link https://chernega.eu.org/
 *
 *
 */

namespace Fyennyi\AsyncCache\Bridge\Laravel;

use Fyennyi\AsyncCache\AsyncCacheManager;
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
