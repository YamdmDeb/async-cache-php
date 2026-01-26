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
 * Fluent builder for AsyncCacheManager to improve Developer Experience
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

    public function __construct(private CacheInterface $cacheAdapter)
    {
    }

    public static function create(CacheInterface $cacheAdapter): self
    {
        return new self($cacheAdapter);
    }

    public function withRateLimiter(RateLimiterInterface $rateLimiter): self
    {
        $this->rateLimiter = $rateLimiter;
        return $this;
    }

    public function withRateLimiterType(RateLimiterType $type): self
    {
        $this->rateLimiterType = $type;
        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function withLockProvider(LockInterface $lockProvider): self
    {
        $this->lockProvider = $lockProvider;
        return $this;
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function withEventDispatcher(EventDispatcherInterface $dispatcher): self
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    public function withSerializer(SerializerInterface $serializer): self
    {
        $this->serializer = $serializer;
        return $this;
    }

    public function build(): AsyncCacheManager
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
