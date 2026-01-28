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

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Bridge\PromiseAdapter;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Pipeline;
use Fyennyi\AsyncCache\Middleware\AsyncLockMiddleware;
use Fyennyi\AsyncCache\Middleware\CacheLookupMiddleware;
use Fyennyi\AsyncCache\Middleware\CoalesceMiddleware;
use Fyennyi\AsyncCache\Middleware\SourceFetchMiddleware;
use Fyennyi\AsyncCache\Middleware\StaleOnErrorMiddleware;
use Fyennyi\AsyncCache\Middleware\TagValidationMiddleware;
use Fyennyi\AsyncCache\Serializer\SerializerInterface;
use Fyennyi\AsyncCache\Storage\AsyncCacheAdapterInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Fyennyi\AsyncCache\Storage\PsrToAsyncAdapter;
use Fyennyi\AsyncCache\Storage\ReactCacheAdapter;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
use React\Cache\CacheInterface as ReactCacheInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\Timer\resolve as delay;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * Universal Asynchronous Cache Manager powered by ReactPHP Promises
 */
class AsyncCacheManager
{
    private Pipeline $pipeline;
    private CacheStorage $storage;
    private LockFactory $lock_factory;

    /**
     * @param  PsrCacheInterface|ReactCacheInterface|AsyncCacheAdapterInterface  $cache_adapter  The cache implementation
     * @param  LimiterInterface|null                                             $rate_limiter   The Symfony Rate Limiter implementation
     * @param  LoggerInterface|null                                              $logger         The PSR-3 logger implementation
     * @param  LockFactory|null                                                  $lock_factory   The Symfony Lock Factory
     * @param  \Fyennyi\AsyncCache\Middleware\MiddlewareInterface[]              $middlewares    Optional custom middleware stack
     * @param  EventDispatcherInterface|null                                     $dispatcher     The PSR-14 event dispatcher
     * @param  SerializerInterface|null                                          $serializer     The custom serializer
     */
    public function __construct(
        PsrCacheInterface|ReactCacheInterface|AsyncCacheAdapterInterface $cache_adapter,
        private ?LimiterInterface $rate_limiter = null,
        private ?LoggerInterface $logger = null,
        ?LockFactory $lock_factory = null,
        array $middlewares = [],
        private ?EventDispatcherInterface $dispatcher = null,
        ?SerializerInterface $serializer = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();

        // Auto-detect and wrap the adapter type for full async support
        if ($cache_adapter instanceof PsrCacheInterface) {
            $cache_adapter = new PsrToAsyncAdapter($cache_adapter);
        } elseif ($cache_adapter instanceof ReactCacheInterface) {
            $cache_adapter = new ReactCacheAdapter($cache_adapter);
        }

        $this->storage = new CacheStorage($cache_adapter, $this->logger, $serializer);
        $this->lock_factory = $lock_factory ?? new LockFactory(new SemaphoreStore());

        $default_middlewares = [
            new CoalesceMiddleware(),
            new StaleOnErrorMiddleware($this->logger, $this->dispatcher),
            new CacheLookupMiddleware($this->storage, $this->logger, $this->dispatcher),
            new TagValidationMiddleware($this->storage, $this->logger),
            new AsyncLockMiddleware($this->lock_factory, $this->storage, $this->logger, $this->dispatcher),
            new SourceFetchMiddleware($this->storage, $this->logger, $this->dispatcher)
        ];

        $this->pipeline = new Pipeline(array_merge($middlewares, $default_middlewares));
    }

    /**
     * Wraps an operation with caching and returns a Promise
     *
     * @param  string        $key              Unique cache key identifier for the operation
     * @param  callable      $promise_factory  Callback function that returns a value or a promise
     * @param  CacheOptions  $options          Caching configuration for this specific request
     * @return PromiseInterface                A promise representing the eventual result of the operation
     */
    public function wrap(string $key, callable $promise_factory, CacheOptions $options) : PromiseInterface
    {
        $context = new CacheContext($key, $promise_factory, $options);

        // Execute the pipeline.
        return $this->pipeline->send($context, function (CacheContext $ctx) {
            try {
                return PromiseAdapter::toPromise(($ctx->promise_factory)());
            } catch (\Throwable $e) {
                return \React\Promise\reject($e);
            }
        });
    }

    /**
     * Atomically increments a cached integer value
     *
     * @param  string             $key      The cache key to increment identifier
     * @param  int                $step     Value to add to the existing counter
     * @param  CacheOptions|null  $options  Optional caching options for persistence
     * @return PromiseInterface             Promise resolving to the new integer value after increment
     */
    public function increment(string $key, int $step = 1, ?CacheOptions $options = null) : PromiseInterface
    {
        $options = $options ?? new CacheOptions();
        $lock_key = 'lock:counter:' . $key;
        $master_deferred = new Deferred();

        $start_time = microtime(true);
        $timeout = 10.0;

        $attempt = function () use (&$attempt, $key, $step, $options, $lock_key, $master_deferred, $start_time, $timeout) {
            $lock = $this->lock_factory->createLock($lock_key, 10.0);

            if ($lock->acquire(false)) {
                $this->storage->get($key, $options)->then(
                    function ($item) use ($key, $step, $options, $lock, $master_deferred) {
                        try {
                            /** @var \Fyennyi\AsyncCache\Model\CachedItem|null $item */
                            $current_value = ($item && is_numeric($item->data)) ? (int) $item->data : 0;
                            $new_value = $current_value + $step;

                            $this->storage->set($key, $new_value, $options)->then(
                                function () use ($lock, $master_deferred, $new_value) {
                                    $lock->release();
                                    $master_deferred->resolve($new_value);
                                },
                                function ($e) use ($lock, $master_deferred) {
                                    $lock->release();
                                    $master_deferred->reject($e);
                                }
                            );
                        } catch (\Throwable $e) {
                            $lock->release();
                            $master_deferred->reject($e);
                        }
                    },
                    function ($e) use ($lock, $master_deferred) {
                        $lock->release();
                        $master_deferred->reject($e);
                    }
                );
                return;
            }

            if (microtime(true) - $start_time >= $timeout) {
                $master_deferred->reject(new \RuntimeException("Could not acquire lock for incrementing key: $key"));
                return;
            }

            delay(0.05)->then($attempt);
        };

        $attempt();

        return $master_deferred->promise();
    }

    /**
     * Atomically decrements a cached integer value
     *
     * @param  string             $key      The cache key identifier to decrement
     * @param  int                $step     Value to subtract from the existing counter
     * @param  CacheOptions|null  $options  Optional caching options for persistence
     * @return PromiseInterface             Promise resolving to the new integer value after decrement
     */
    public function decrement(string $key, int $step = 1, ?CacheOptions $options = null) : PromiseInterface
    {
        return $this->increment($key, -$step, $options);
    }

    /**
     * Invalidates all cache entries associated with the given tags
     *
     * @param  string[]  $tags  List of tag names to invalidate
     * @return PromiseInterface Resolves to true on successful invalidation
     */
    public function invalidateTags(array $tags) : PromiseInterface
    {
        return $this->storage->invalidateTags($tags);
    }

    /**
     * Clears the entire cache storage
     *
     * @return PromiseInterface Resolves to true on successful wipe
     */
    public function clear() : PromiseInterface
    {
        return $this->storage->clear();
    }

    /**
     * Deletes a specific item from the cache
     *
     * @param  string  $key  Item identifier to remove
     * @return PromiseInterface Promise resolving to true on successful deletion
     */
    public function delete(string $key) : PromiseInterface
    {
        return $this->storage->delete($key);
    }

    /**
     * Returns the rate limiter instance
     *
     * @return LimiterInterface|null The Symfony Rate Limiter or null if not set
     */
    public function getRateLimiter() : ?LimiterInterface
    {
        return $this->rate_limiter;
    }

    /**
     * Resets the rate limiter state
     *
     * @return void
     */
    public function clearRateLimiter() : void
    {
        $this->rate_limiter?->reset();
    }
}
