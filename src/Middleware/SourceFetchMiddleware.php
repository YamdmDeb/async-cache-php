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
use Fyennyi\AsyncCache\Event\CacheMissEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * The final middleware that calls the source and populates the cache using Futures
 */
class SourceFetchMiddleware implements MiddlewareInterface
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
     * Fetches fresh data from the source and updates cache
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain (usually empty destination)
     * @return Future                  Future resolving to freshly fetched data
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        $this->dispatcher?->dispatch(new CacheMissEvent($context->key));

        $fetchStartTime = microtime(true);

        try {
            // We call the factory and wrap the result in our native Future
            $sourceResult = ($context->promiseFactory)();
            $sourceFuture = \Fyennyi\AsyncCache\Bridge\PromiseAdapter::toFuture($sourceResult);
        } catch (\Throwable $e) {
            $deferred = new Deferred();
            $deferred->reject($e);
            return $deferred->future();
        }

        // Create a new deferred for the "after-save" result
        $finalDeferred = new Deferred();

        $sourceFuture->onResolve(
            function ($data) use ($context, $fetchStartTime, $finalDeferred) {
                try {
                    $generationTime = microtime(true) - $fetchStartTime;
                    $this->storage->set($context->key, $data, $context->options, $generationTime);

                    $this->dispatcher?->dispatch(new CacheStatusEvent(
                        $context->key,
                        CacheStatus::Miss,
                        microtime(true) - $context->startTime,
                        $context->options->tags
                    ));

                    $finalDeferred->resolve($data);
                } catch (\Throwable $e) {
                    // If saving fails, we still might want to return the data, or log error
                    // For now, we propagate the error if storage fails critically
                    $finalDeferred->reject($e);
                }
            },
            function ($reason) use ($context, $finalDeferred) {
                $this->logger->error('AsyncCache FETCH_ERROR: failed to fetch fresh data', [
                    'key' => $context->key,
                    'reason' => $reason
                ]);

                $finalDeferred->reject($reason instanceof \Throwable ? $reason : new \RuntimeException((string)$reason));
            }
        );

        return $finalDeferred->future();
    }
}
