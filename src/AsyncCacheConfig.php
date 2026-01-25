<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterFactory;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Psr\SimpleCache\CacheInterface;

/*
 * Configuration for AsyncCacheManager
 */
class AsyncCacheConfig
{
    public function __construct(
        private CacheInterface $cache,
        private RateLimiterType $rateLimiterType = RateLimiterType::Auto,
        private array $rateLimiterOptions = []
    ) {
    }

    /**
     * Create rate limiter based on configuration
     */
    public function createRateLimiter() : RateLimiterInterface
    {
        return RateLimiterFactory::create($this->rateLimiterType, $this->cache);
    }
}
