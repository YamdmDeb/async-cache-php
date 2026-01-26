<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Fyennyi\AsyncCache\Serializer\SerializerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Fluent builder for AsyncCacheManager focused on ReactPHP and Fibers
 */
class AsyncCacheBuilder
{
    private ?RateLimiterInterface $rateLimiter = null;
    private RateLimiterType $rateLimiterType = RateLimiterType::Auto;
    private ?LoggerInterface $logger = null;
    private ?LockInterface $lockProvider = null;
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    private ?EventDispatcherInterface $dispatcher = null;
    private ?SerializerInterface $serializer = null;

    /**
     * @param  CacheInterface  $cacheAdapter  The underlying PSR-16 cache implementation
     */
    public function __construct(private CacheInterface $cacheAdapter)
    {
    }

    /**
     * Entry point for the fluent builder
     *
     * @param  CacheInterface  $cacheAdapter  The underlying cache storage
     * @return self                           New builder instance
     */
    public static function create(CacheInterface $cacheAdapter) : self
    {
        return new self($cacheAdapter);
    }

    /**
     * Sets a custom rate limiter implementation
     *
     * @param  RateLimiterInterface  $rateLimiter  The implementation to use
     * @return self                                Current builder instance
     */
    public function withRateLimiter(RateLimiterInterface $rateLimiter) : self
    {
        $this->rateLimiter = $rateLimiter;
        return $this;
    }

    /**
     * Configures the automatic rate limiter type
     *
     * @param  RateLimiterType  $type  Enum identifier for the rate limiter
     * @return self                    Current builder instance
     */
    public function withRateLimiterType(RateLimiterType $type) : self
    {
        $this->rateLimiterType = $type;
        return $this;
    }

    /**
     * Sets the PSR-3 logger
     *
     * @param  LoggerInterface  $logger  Logger implementation
     * @return self                      Current builder instance
     */
    public function withLogger(LoggerInterface $logger) : self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Configures the distributed lock provider
     *
     * @param  LockInterface  $lockProvider  Lock implementation
     * @return self                          Current builder instance
     */
    public function withLockProvider(LockInterface $lockProvider) : self
    {
        $this->lockProvider = $lockProvider;
        return $this;
    }

    /**
     * Appends a custom middleware to the pipeline
     *
     * @param  MiddlewareInterface  $middleware  Middleware implementation
     * @return self                              Current builder instance
     */
    public function withMiddleware(MiddlewareInterface $middleware) : self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Sets the PSR-14 event dispatcher
     *
     * @param  EventDispatcherInterface  $dispatcher  Dispatcher implementation
     * @return self                                    Current builder instance
     */
    public function withEventDispatcher(EventDispatcherInterface $dispatcher) : self
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    /**
     * Sets a custom serializer for data storage
     *
     * @param  SerializerInterface  $serializer  Serializer implementation
     * @return self                              Current builder instance
     */
    public function withSerializer(SerializerInterface $serializer) : self
    {
        $this->serializer = $serializer;
        return $this;
    }

    /**
     * Finalizes the configuration and creates the AsyncCacheManager
     *
     * @return AsyncCacheManager  Fully configured manager instance
     */
    public function build() : AsyncCacheManager
    {
        return new AsyncCacheManager(
            $this->cacheAdapter,
            $this->rateLimiter,
            $this->rateLimiterType,
            $this->logger,
            $this->lockProvider,
            $this->middlewares,
            $this->dispatcher,
            $this->serializer
        );
    }
}
