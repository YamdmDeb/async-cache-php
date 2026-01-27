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

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Future;

/**
 * Implementation of the Singleflight pattern using passive Futures to share results
 */
class CoalesceMiddleware implements MiddlewareInterface
{
    /** @var array<string, Future> Tracks currently in-flight futures by key */
    private static array $in_flight = [];

    /**
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  The (possibly shared) result future
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        $key = $context->key;

        if (isset(self::$in_flight[$key])) {
            return self::$in_flight[$key];
        }

        $future = $next($context);
        self::$in_flight[$key] = $future;

        // Clean up when the operation completes (success or failure)
        $future->onResolve(
            function () use ($key) {
                unset(self::$in_flight[$key]);
            },
            function () use ($key) {
                unset(self::$in_flight[$key]);
            }
        );

        return $future;
    }
}
