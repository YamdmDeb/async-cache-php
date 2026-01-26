<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterFactory;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Static configuration for AsyncCacheManager
 */
class AsyncCacheConfig
{
    /**
     * @param  CacheInterface   $cache               The underlying cache adapter
     * @param  RateLimiterType  $rateLimiterType     Default rate limiter strategy
     * @param  array            $rateLimiterOptions  Additional options for the rate limiter
     */
    public function __construct(
        private CacheInterface $cache,
        private RateLimiterType $rateLimiterType = RateLimiterType::Auto,
        private array $rateLimiterOptions = []
    ) {
    }

    /**
     * Create rate limiter based on current configuration
     *
     * @return RateLimiterInterface
     */
    public function createRateLimiter() : RateLimiterInterface
    {
        return RateLimiterFactory::create($this->rateLimiterType, $this->cache);
    }
}
