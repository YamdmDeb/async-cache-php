<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Core\CacheResolver;
use Fyennyi\AsyncCache\Core\Pipeline;
use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Fyennyi\AsyncCache\Lock\InMemoryLockAdapter;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterFactory;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

class AsyncCacheManager
{
    private CacheResolver $resolver;
    private Pipeline $pipeline;

    /**
     * @param  CacheInterface  $cache_adapter  The PSR-16 cache implementation
     * @param  RateLimiterInterface|null  $rate_limiter  The rate limiter implementation
     * @param  RateLimiterType  $rate_limiter_type  Type of rate limiter to use
     * @param  LoggerInterface|null  $logger  The PSR-3 logger implementation
     * @param  LockInterface|null  $lock_provider  The distributed lock provider
     * @param  MiddlewareInterface[]  $middlewares  Optional middleware stack
     * @param  EventDispatcherInterface|null  $dispatcher  The PSR-14 event dispatcher
     */
    public function __construct(
        private CacheInterface $cache_adapter,
        private ?RateLimiterInterface $rate_limiter = null,
        private RateLimiterType $rate_limiter_type = RateLimiterType::Auto,
        private ?LoggerInterface $logger = null,
        private ?LockInterface $lock_provider = null,
        array $middlewares = [],
        private ?EventDispatcherInterface $dispatcher = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
        
        $storage = new CacheStorage($this->cache_adapter, $this->logger);
        $this->lock_provider = $this->lock_provider ?? new InMemoryLockAdapter();

        if ($this->rate_limiter === null) {
            $this->rate_limiter = RateLimiterFactory::create($this->rate_limiter_type, $this->cache_adapter);
        }

        $this->resolver = new CacheResolver(
            $storage,
            $this->rate_limiter,
            $this->lock_provider,
            $this->logger,
            $this->dispatcher
        );

        $this->pipeline = new Pipeline($middlewares);
    }

    /**
     * Wraps an asynchronous operation with caching, rate limiting, and stale-data fallback
     */
    public function wrap(string $key, callable $promise_factory, CacheOptions $options) : PromiseInterface
    {
        return $this->pipeline->send($key, $promise_factory, $options, function ($k, $f, $o) {
            return $this->resolver->resolve($k, $f, $o);
        });
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
