<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Bridge\PromiseAdapter;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Pipeline;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Fyennyi\AsyncCache\Lock\InMemoryLockAdapter;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Middleware\AsyncLockMiddleware;
use Fyennyi\AsyncCache\Middleware\CacheLookupMiddleware;
use Fyennyi\AsyncCache\Middleware\SourceFetchMiddleware;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterFactory;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Fyennyi\AsyncCache\Scheduler\ReactScheduler;
use Fyennyi\AsyncCache\Scheduler\SchedulerInterface;
use Fyennyi\AsyncCache\Serializer\SerializerInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use function React\Async\await;

/**
 * Universal Asynchronous Cache Manager powered by native Futures.
 * Independent of external promise libraries in its core.
 */
class AsyncCacheManager
{
    private Pipeline $pipeline;
    private CacheStorage $storage;
    private SchedulerInterface $scheduler;

    public function __construct(
        private CacheInterface $cache_adapter,
        private ?RateLimiterInterface $rate_limiter = null,
        private RateLimiterType $rate_limiter_type = RateLimiterType::Auto,
        private ?LoggerInterface $logger = null,
        private ?LockInterface $lock_provider = null,
        array $middlewares = [],
        private ?EventDispatcherInterface $dispatcher = null,
        ?SerializerInterface $serializer = null,
        ?SchedulerInterface $scheduler = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
        $this->storage = new CacheStorage($this->cache_adapter, $this->logger, $serializer);
        $this->lock_provider = $this->lock_provider ?? new InMemoryLockAdapter();
        $this->scheduler = $scheduler ?? new ReactScheduler();

        if ($this->rate_limiter === null) {
            $this->rate_limiter = RateLimiterFactory::create($this->rate_limiter_type, $this->cache_adapter);
        }

        if (empty($middlewares)) {
            $middlewares = [
                new CacheLookupMiddleware($this->storage, $this->scheduler, $this->logger, $this->dispatcher),
                new AsyncLockMiddleware($this->lock_provider, $this->storage, $this->scheduler, $this->logger, $this->dispatcher),
                new SourceFetchMiddleware($this->storage, $this->logger, $this->dispatcher)
            ];
        }

        $this->pipeline = new Pipeline($middlewares);
    }

    /**
     * Internal core method. Returns native Future.
     */
    public function wrapFuture(string $key, callable $promise_factory, CacheOptions $options): Future
    {
        $context = new CacheContext($key, $promise_factory, $options);
        return $this->pipeline->send($context, function (CacheContext $ctx) {
            // Final destination: just call the source and return a Future
            $res = ($ctx->promiseFactory)();
            $deferred = new \Fyennyi\AsyncCache\Core\Deferred();
            if (method_exists($res, 'then')) {
                $res->then(fn($v) => $deferred->resolve($v), fn($r) => $deferred->reject($r));
            } else {
                $deferred->resolve($res);
            }
            return $deferred->future();
        });
    }

    /**
     * Standard API: Returns a Guzzle Promise (Industry Standard)
     */
    public function wrap(string $key, callable $promise_factory, CacheOptions $options): GuzzlePromiseInterface
    {
        return PromiseAdapter::toGuzzle($this->wrapFuture($key, $promise_factory, $options));
    }

    /**
     * Modern API: Pure Fiber access.
     */
    public function get(string $key, callable $promise_factory, CacheOptions $options): mixed
    {
        $future = $this->wrapFuture($key, $promise_factory, $options);
        
        // Use react/async to unwrap our future by bridging it to React first
        return await(PromiseAdapter::toReact($future));
    }

    /**
     * Increment using native Futures.
     */
    public function increment(string $key, int $step = 1, ?CacheOptions $options = null): GuzzlePromiseInterface
    {
        $options = $options ?? new CacheOptions();
        $lockKey = 'lock:counter:' . $key;
        $deferred = new \Fyennyi\AsyncCache\Core\Deferred();
        
        if ($this->lock_provider->acquire($lockKey, 10.0, true)) {
            $item = $this->storage->get($key, $options);
            $currentValue = $item ? (int) $item->data : 0;
            $newValue = $currentValue + $step;
            $this->storage->set($key, $newValue, $options);
            $this->lock_provider->release($lockKey);
            $deferred->resolve($newValue);
        } else {
            $deferred->reject(new \RuntimeException("Could not acquire lock for incrementing key: $key"));
        }

        return PromiseAdapter::toGuzzle($deferred->future());
    }

    public function decrement(string $key, int $step = 1, ?CacheOptions $options = null): GuzzlePromiseInterface
    {
        return $this->increment($key, -$step, $options);
    }

    public function invalidateTags(array $tags) : void { $this->storage->invalidateTags($tags); }
    public function clear() : bool { return $this->cache_adapter->clear(); }
    public function delete(string $key) : bool { return $this->cache_adapter->delete($key); }
    public function getRateLimiter() : RateLimiterInterface { return $this->rate_limiter; }
    public function clearRateLimiter(?string $key = null) : void { $this->rate_limiter->clear($key); }
}