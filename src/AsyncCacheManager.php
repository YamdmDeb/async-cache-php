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
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Core\Pipeline;
use Fyennyi\AsyncCache\Core\Timer;
use Fyennyi\AsyncCache\Middleware\AsyncLockMiddleware;
use Fyennyi\AsyncCache\Middleware\CacheLookupMiddleware;
use Fyennyi\AsyncCache\Middleware\CoalesceMiddleware;
use Fyennyi\AsyncCache\Middleware\SourceFetchMiddleware;
use Fyennyi\AsyncCache\Middleware\StaleOnErrorMiddleware;
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
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * Universal Asynchronous Cache Manager powered by native Futures
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

        if (empty($middlewares)) {
            $middlewares = [
                new CoalesceMiddleware(),
                new StaleOnErrorMiddleware($this->logger, $this->dispatcher),
                new CacheLookupMiddleware($this->storage, $this->logger, $this->dispatcher),
                new AsyncLockMiddleware($this->lock_factory, $this->storage, $this->logger, $this->dispatcher),
                new SourceFetchMiddleware($this->storage, $this->logger, $this->dispatcher)
            ];
        }

        $this->pipeline = new Pipeline($middlewares);
    }

    /**
     * Wraps an operation with caching and returns a library-native Future
     *
     * @param  string        $key              Cache key identifier
     * @param  callable      $promise_factory  Function that returns a value or promise
     * @param  CacheOptions  $options          Caching configuration
     * @return Future                          Native future representing the result
     */
    public function wrap(string $key, callable $promise_factory, CacheOptions $options) : Future
    {
        $context = new CacheContext($key, $promise_factory, $options);

        // Execute the pipeline. The result is a Future from the last middleware.
        return $this->pipeline->send($context, function (CacheContext $ctx) {
            try {
                /** @var callable $factory */
                $factory = $ctx->promise_factory;
                $res = $factory();
                return PromiseAdapter::toFuture($res);
            } catch (\Throwable $e) {
                $deferred = new Deferred();
                $deferred->reject($e);
                return $deferred->future();
            }
        });
    }

    /**
     * Atomically increments a cached integer value
     *
     * @param  string             $key      The key to increment
     * @param  int                $step     Increment step value
     * @param  CacheOptions|null  $options  Optional caching options
     * @return Future                       Future resolving to the new value
     */
    public function increment(string $key, int $step = 1, ?CacheOptions $options = null) : Future
    {
        $options = $options ?? new CacheOptions();
        $lock_key = 'lock:counter:' . $key;
        $deferred = new Deferred();

        $start_time = microtime(true);
        $timeout = 10.0;

        $attempt = function () use (&$attempt, $key, $step, $options, $lock_key, $deferred, $start_time, $timeout) {
            $lock = $this->lock_factory->createLock($lock_key, 10.0);

            if ($lock->acquire(false)) {
                $this->storage->get($key, $options)->onResolve(
                    function ($item) use ($key, $step, $options, $lock, $deferred) {
                        try {
                            /** @var \Fyennyi\AsyncCache\Model\CachedItem|null $item */
                            $current_value = ($item && is_numeric($item->data)) ? (int) $item->data : 0;
                            $new_value = $current_value + $step;

                            $this->storage->set($key, $new_value, $options)->onResolve(
                                function () use ($lock, $deferred, $new_value) {
                                    $lock->release();
                                    $deferred->resolve($new_value);
                                },
                                function ($e) use ($lock, $deferred) {
                                    $lock->release();
                                    $deferred->reject($e);
                                }
                            );
                        } catch (\Throwable $e) {
                            $lock->release();
                            $deferred->reject($e);
                        }
                    },
                    function ($e) use ($lock, $deferred) {
                        $lock->release();
                        $deferred->reject($e);
                    }
                );
                return;
            }

            if (microtime(true) - $start_time >= $timeout) {
                $deferred->reject(new \RuntimeException("Could not acquire lock for incrementing key: $key"));
                return;
            }

            Timer::delay(0.05)->onResolve($attempt);
        };

        $attempt();

        return $deferred->future();
    }

    /**
     * Atomically decrements a cached integer value
     *
     * @param  string             $key      The key to decrement
     * @param  int                $step     Decrement step value
     * @param  CacheOptions|null  $options  Optional caching options
     * @return Future                       Future resolving to the new value
     */
    public function decrement(string $key, int $step = 1, ?CacheOptions $options = null) : Future
    {
        return $this->increment($key, -$step, $options);
    }

    /**
     * Invalidates all cache entries associated with the given tags
     *
     * @param  string[]  $tags  List of tags to invalidate
     * @return Future           Resolves to true on success
     */
    public function invalidateTags(array $tags) : Future
    {
        return $this->storage->invalidateTags($tags);
    }

    /**
     * Clears the entire cache storage
     *
     * @return Future Resolves to true on success
     */
    public function clear() : Future
    {
        return $this->storage->clear();
    }

    /**
     * Deletes a specific item from the cache
     *
     * @param  string  $key  Item identifier
     * @return Future        Resolves to true on success
     */
    public function delete(string $key) : Future
    {
        return $this->storage->delete($key);
    }

    /**
     * Returns the rate limiter instance
     *
     * @return LimiterInterface|null
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
