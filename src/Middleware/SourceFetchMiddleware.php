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

use Fyennyi\AsyncCache\Bridge\PromiseAdapter;
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
 * The final middleware that calls the source and populates the cache asynchronously
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
     * Fetches fresh data from the source and updates cache asynchronously
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain (usually empty destination)
     * @return Future                  Future resolving to freshly fetched data
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        $this->dispatcher?->dispatch(new CacheMissEvent($context->key));

        $fetch_start_time = microtime(true);
        $deferred = new Deferred();

        try {
            /** @var callable $factory */
            $factory = $context->promise_factory;
            $source_result = $factory();
            $source_future = PromiseAdapter::toFuture($source_result);
        } catch (\Throwable $e) {
            $deferred->reject($e);
            return $deferred->future();
        }

        $source_future->onResolve(
            function ($data) use ($context, $fetch_start_time, $deferred) {
                $generation_time = microtime(true) - $fetch_start_time;

                // Asynchronously populate the cache
                $this->storage->set($context->key, $data, $context->options, $generation_time);

                $this->dispatcher?->dispatch(new CacheStatusEvent(
                    $context->key,
                    CacheStatus::Miss,
                    microtime(true) - $context->start_time,
                    $context->options->tags
                ));

                $deferred->resolve($data);
            },
            function ($reason) use ($context, $deferred) {
                $msg = $reason instanceof \Throwable ? $reason->getMessage() : (\is_scalar($reason) || $reason instanceof \Stringable ? (string)$reason : 'Unknown error');
                $this->logger->error('AsyncCache FETCH_ERROR', [
                    'key' => $context->key,
                    'reason' => $msg
                ]);
                $deferred->reject($reason instanceof \Throwable ? $reason : new \RuntimeException($msg));
            }
        );

        return $deferred->future();
    }
}
