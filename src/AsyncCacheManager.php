<?php

namespace Fyennyi\AsyncCache;

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
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
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

    /**
     * @param  CacheInterface                 $cache_adapter  The PSR-16 cache implementation
     * @param  LimiterInterface|null          $rate_limiter   The Symfony Rate Limiter implementation
     * @param  LoggerInterface|null           $logger         The PSR-3 logger implementation
     * @param  LockFactory|null               $lock_factory   The Symfony Lock Factory
     * @param  array                          $middlewares    Optional custom middleware stack
     * @param  EventDispatcherInterface|null  $dispatcher     The PSR-14 event dispatcher
     * @param  SerializerInterface|null       $serializer     The custom serializer
     */
    public function __construct(
        private CacheInterface $cache_adapter,
        private ?LimiterInterface $rate_limiter = null,
        private ?LoggerInterface $logger = null,
        private ?LockFactory $lock_factory = null,
        array $middlewares = [],
        private ?EventDispatcherInterface $dispatcher = null,
        ?SerializerInterface $serializer = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
        $this->storage = new CacheStorage($this->cache_adapter, $this->logger, $serializer);
        $this->lock_factory = $this->lock_factory ?? new LockFactory(new SemaphoreStore());

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
     * @template T
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
                $res = ($ctx->promiseFactory)();
                return \Fyennyi\AsyncCache\Bridge\PromiseAdapter::toFuture($res);
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
        $lockKey = 'lock:counter:' . $key;
        $deferred = new Deferred();

        $startTime = microtime(true);
        $timeout = 10.0;

        $attempt = function () use (&$attempt, $key, $step, $options, $lockKey, $deferred, $startTime, $timeout) {
            $lock = $this->lock_factory->createLock($lockKey, 10.0);

            if ($lock->acquire(false)) {
                try {
                    $item = $this->storage->get($key, $options);
                    $currentValue = $item ? (int) $item->data : 0;
                    $newValue = $currentValue + $step;
                    $this->storage->set($key, $newValue, $options);
                    $lock->release();
                    $deferred->resolve($newValue);
                } catch (\Throwable $e) {
                    $lock->release();
                    $deferred->reject($e);
                }
                return;
            }

            if (microtime(true) - $startTime >= $timeout) {
                $deferred->reject(new \RuntimeException("Could not acquire lock for incrementing key: $key"));
                return;
            }

            Timer::delay(0.05)->onResolve(function() use ($attempt) {
                $attempt();
            });
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
     * @param  array  $tags  List of tags to invalidate
     * @return void
     */
    public function invalidateTags(array $tags) : void
    {
        $this->storage->invalidateTags($tags);
    }

    /**
     * Clears the entire cache storage
     *
     * @return bool True on success, false on failure
     */
    public function clear() : bool
    {
        return $this->cache_adapter->clear();
    }

    /**
     * Deletes a specific item from the cache
     *
     * @param  string  $key  Item identifier
     * @return bool          True on success, false on failure
     */
    public function delete(string $key) : bool
    {
        return $this->cache_adapter->delete($key);
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
