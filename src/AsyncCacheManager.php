<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Bridge\GuzzlePromiseAdapter;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Pipeline;
use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Fyennyi\AsyncCache\Lock\InMemoryLockAdapter;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Middleware\AsyncLockMiddleware;
use Fyennyi\AsyncCache\Middleware\CacheLookupMiddleware;
use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;
use Fyennyi\AsyncCache\Middleware\SourceFetchMiddleware;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterFactory;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Fyennyi\AsyncCache\Serializer\SerializerInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

class AsyncCacheManager
{
    private Pipeline $pipeline;
    private CacheStorage $storage;

    public function __construct(
        private CacheInterface $cache_adapter,
        private ?RateLimiterInterface $rate_limiter = null,
        private RateLimiterType $rate_limiter_type = RateLimiterType::Auto,
        private ?LoggerInterface $logger = null,
        private ?LockInterface $lock_provider = null,
        array $middlewares = [],
        private ?EventDispatcherInterface $dispatcher = null,
        ?SerializerInterface $serializer = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
        $this->storage = new CacheStorage($this->cache_adapter, $this->logger, $serializer);
        $this->lock_provider = $this->lock_provider ?? new InMemoryLockAdapter();

        if ($this->rate_limiter === null) {
            $this->rate_limiter = RateLimiterFactory::create($this->rate_limiter_type, $this->cache_adapter);
        }

        // Automatic async synchronization
        GuzzlePromiseAdapter::registerLoop();

        // Build default pipeline if empty
        if (empty($middlewares)) {
            $middlewares = [
                new CacheLookupMiddleware($this->storage, $this->logger, $this->dispatcher),
                new AsyncLockMiddleware($this->lock_provider, $this->storage, $this->logger, $this->dispatcher),
                new SourceFetchMiddleware($this->storage, $this->logger, $this->dispatcher)
            ];
        }

        $this->pipeline = new Pipeline($middlewares);
    }

    /**
     * Wraps an asynchronous operation with caching, rate limiting, and stale-data fallback
     */
    public function wrap(string $key, callable $promise_factory, CacheOptions $options) : PromiseInterface
    {
        $context = new CacheContext($key, $promise_factory, $options);
        
        return $this->pipeline->send($context, function (CacheContext $ctx) {
            // This destination is reached only if all middlewares pass and none return early
            return GuzzlePromiseAdapter::wrap(($ctx->promiseFactory)());
        });
    }

    /**
     * Atomically increments a cached integer value
     * 
     * @return PromiseInterface<int>
     */
    public function increment(string $key, int $step = 1, ?CacheOptions $options = null): PromiseInterface
    {
        $options = $options ?? new CacheOptions();
        $lockKey = 'lock:counter:' . $key;

        try {
            // Acquire blocking lock to ensure atomicity
            if ($this->lock_provider->acquire($lockKey, 10.0, true)) {
                $item = $this->storage->get($key, $options);
                $currentValue = $item ? (int) $item->data : 0;
                $newValue = $currentValue + $step;

                $this->storage->set($key, $newValue, $options);
                $this->lock_provider->release($lockKey);

                return Create::promiseFor($newValue);
            }

            return Create::rejectionFor(new \RuntimeException("Could not acquire lock for incrementing key: $key"));
        } catch (\Throwable $e) {
            $this->lock_provider->release($lockKey);
            return Create::rejectionFor($e);
        }
    }

    /**
     * Atomically decrements a cached integer value
     * 
     * @return PromiseInterface<int>
     */
    public function decrement(string $key, int $step = 1, ?CacheOptions $options = null): PromiseInterface
    {
        return $this->increment($key, -$step, $options);
    }

    /**
     * Invalidates all cache entries associated with the given tags
     */
    public function invalidateTags(array $tags) : void
    {
        $this->storage->invalidateTags($tags);
    }

    /**
     * Proxy methods
     */
    public function clear() : bool { return $this->cache_adapter->clear(); }
    public function delete(string $key) : bool { return $this->cache_adapter->delete($key); }
    public function getRateLimiter() : RateLimiterInterface { return $this->rate_limiter; }
    public function clearRateLimiter(?string $key = null) : void {
        $this->rate_limiter->clear($key);
    }
}
