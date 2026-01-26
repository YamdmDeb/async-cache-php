<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Bridge\PromiseAdapter;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Core\Pipeline;
use Fyennyi\AsyncCache\Core\Timer;
use Fyennyi\AsyncCache\Lock\InMemoryLockAdapter;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Middleware\AsyncLockMiddleware;
use Fyennyi\AsyncCache\Middleware\CacheLookupMiddleware;
use Fyennyi\AsyncCache\Middleware\CoalesceMiddleware;
use Fyennyi\AsyncCache\Middleware\SourceFetchMiddleware;
use Fyennyi\AsyncCache\Middleware\StaleOnErrorMiddleware;
use Fyennyi\AsyncCache\Serializer\SerializerInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use React\Promise\PromiseInterface as ReactPromiseInterface;
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
     * @param  LockInterface|null             $lock_provider  The distributed lock provider
     * @param  array                          $middlewares    Optional custom middleware stack
     * @param  EventDispatcherInterface|null  $dispatcher     The PSR-14 event dispatcher
     * @param  SerializerInterface|null       $serializer     The custom serializer
     */
    public function __construct(
        private CacheInterface $cache_adapter,
        private ?LimiterInterface $rate_limiter = null,
        private ?LoggerInterface $logger = null,
        private ?LockInterface $lock_provider = null,
        array $middlewares = [],
        private ?EventDispatcherInterface $dispatcher = null,
        ?SerializerInterface $serializer = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
        $this->storage = new CacheStorage($this->cache_adapter, $this->logger, $serializer);
        $this->lock_provider = $this->lock_provider ?? new InMemoryLockAdapter();

        if (empty($middlewares)) {
            $middlewares = [
                new CoalesceMiddleware(),
                new StaleOnErrorMiddleware($this->logger, $this->dispatcher),
                new CacheLookupMiddleware($this->storage, $this->logger, $this->dispatcher),
                new AsyncLockMiddleware($this->lock_provider, $this->storage, $this->logger, $this->dispatcher),
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
    public function wrapFuture(string $key, callable $promise_factory, CacheOptions $options) : Future
    {
        $context = new CacheContext($key, $promise_factory, $options);
        
        // Execute the pipeline. The result is a Future from the last middleware.
        // We provide a fallback destination handler, though SourceFetchMiddleware usually intercepts it.
        return $this->pipeline->send($context, function (CacheContext $ctx) {
            $res = ($ctx->promiseFactory)();
            $deferred = new Deferred();

            if ($res instanceof Future) {
                $res->onResolve(
                    fn($v) => $deferred->resolve($v),
                    fn($r) => $deferred->reject($r)
                );
            } elseif (is_object($res) && method_exists($res, 'then')) {
                $res->then(
                    fn($v) => $deferred->resolve($v),
                    fn($r) => $deferred->reject($r)
                );
            } else {
                $deferred->resolve($res);
            }
            return $deferred->future();
        });
    }

    /**
     * Primary API: Returns a Guzzle Promise for industry compatibility
     *
     * @template T
     *
     * @param  string        $key              Cache key identifier
     * @param  callable      $promise_factory  Function that returns a value or promise
     * @param  CacheOptions  $options          Caching configuration
     * @return GuzzlePromiseInterface          Promise that resolves to the result
     */
    public function wrap(string $key, callable $promise_factory, CacheOptions $options) : GuzzlePromiseInterface
    {
        return PromiseAdapter::toGuzzle($this->wrapFuture($key, $promise_factory, $options));
    }

    /**
     * ReactPHP API: Returns a native ReactPHP Promise for high performance
     *
     * @template T
     *
     * @param  string        $key              Cache key identifier
     * @param  callable      $promise_factory  Function that returns a value or promise
     * @param  CacheOptions  $options          Caching configuration
     * @return ReactPromiseInterface           React promise for high-perf scenarios
     */
    public function wrapReact(string $key, callable $promise_factory, CacheOptions $options) : ReactPromiseInterface
    {
        return PromiseAdapter::toReact($this->wrapFuture($key, $promise_factory, $options));
    }

    /**
     * Fiber API: Returns the result directly using PHP 8.1+ Fibers
     *
     * @template T
     *
     * @param  string        $key              Cache key identifier
     * @param  callable      $promise_factory  Function that returns a value or promise
     * @param  CacheOptions  $options          Caching configuration
     * @return mixed                           The final processed result
     *
     * @throws \Throwable If the operation fails and no stale data is available
     */
    public function get(string $key, callable $promise_factory, CacheOptions $options) : mixed
    {
        return $this->wrapFuture($key, $promise_factory, $options)->wait();
    }

    /**
     * Atomically increments a cached integer value
     *
     * @param  string             $key      The key to increment
     * @param  int                $step     Increment step value
     * @param  CacheOptions|null  $options  Optional caching options
     * @return GuzzlePromiseInterface       Promise resolving to the new value
     */
    public function increment(string $key, int $step = 1, ?CacheOptions $options = null) : GuzzlePromiseInterface
    {
        $options = $options ?? new CacheOptions();
        $lockKey = 'lock:counter:' . $key;
        $deferred = new Deferred();

        $startTime = microtime(true);
        $timeout = 10.0;

        $attempt = function () use (&$attempt, $key, $step, $options, $lockKey, $deferred, $startTime, $timeout) {
            if ($this->lock_provider->acquire($lockKey, 10.0, false)) {
                try {
                    $item = $this->storage->get($key, $options);
                    $currentValue = $item ? (int) $item->data : 0;
                    $newValue = $currentValue + $step;
                    $this->storage->set($key, $newValue, $options);
                    $this->lock_provider->release($lockKey);
                    $deferred->resolve($newValue);
                } catch (\Throwable $e) {
                    $this->lock_provider->release($lockKey);
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

        return PromiseAdapter::toGuzzle($deferred->future());
    }

    /**
     * Atomically decrements a cached integer value
     *
     * @param  string             $key      The key to decrement
     * @param  int                $step     Decrement step value
     * @param  CacheOptions|null  $options  Optional caching options
     * @return GuzzlePromiseInterface       Promise resolving to the new value
     */
    public function decrement(string $key, int $step = 1, ?CacheOptions $options = null) : GuzzlePromiseInterface
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