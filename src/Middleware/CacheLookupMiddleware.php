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
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Enum\CacheStrategy;
use Fyennyi\AsyncCache\Event\CacheHitEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Core middleware for initial cache retrieval and freshness validation
 */
class CacheLookupMiddleware implements MiddlewareInterface
{
    /**
     * @param  CacheStorage                   $storage     The cache interaction layer
     * @param  LoggerInterface                $logger      Logging implementation
     * @param  EventDispatcherInterface|null  $dispatcher  Event dispatcher for telemetry
     */
    public function __construct(
        private CacheStorage $storage,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    /**
     * Performs initial cache lookup and handles freshness validation
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  Future resolving to cached or fresh data
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        if ($context->options->strategy === CacheStrategy::ForceRefresh) {
            $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Bypass, 0, $context->options->tags));
            return $next($context);
        }

        $deferred = new Deferred();

        $this->storage->get($context->key, $context->options)->onResolve(
            function ($cached_item) use ($context, $next, $deferred) {
                if ($cached_item instanceof CachedItem) {
                    $context->staleItem = $cached_item;
                    $is_fresh = $cached_item->isFresh();

                    if ($is_fresh && $context->options->x_fetch_beta > 0 && $cached_item->generationTime > 0) {
                        $rand = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
                        $check = time() - ($cached_item->generationTime * $context->options->x_fetch_beta * log($rand));

                        if ($check > $cached_item->logicalExpireTime) {
                            $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::XFetch, microtime(true) - $context->startTime, $context->options->tags));
                            $is_fresh = false;
                        }
                    }

                    if ($is_fresh) {
                        $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Hit, microtime(true) - $context->startTime, $context->options->tags));
                        $this->dispatcher?->dispatch(new CacheHitEvent($context->key, $cached_item->data));

                        $deferred->resolve($cached_item->data);
                        return;
                    }

                    if ($context->options->strategy === CacheStrategy::Background) {
                        $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Stale, microtime(true) - $context->startTime, $context->options->tags));
                        $this->dispatcher?->dispatch(new CacheHitEvent($context->key, $cached_item->data));
                        $next($context);

                        $deferred->resolve($cached_item->data);
                        return;
                    }
                }

                $next($context)->onResolve(
                    fn($v) => $deferred->resolve($v),
                    fn($e) => $deferred->reject($e)
                );
            },
            fn($e) => $deferred->reject($e)
        );

        return $deferred->future();
    }
}
