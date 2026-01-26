<?php

namespace Fyennyi\AsyncCache\RateLimiter;

use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Psr\SimpleCache\CacheInterface;

/**
 * Factory for creating rate limiter instances based on configuration
 */
class RateLimiterFactory
{
    /**
     * Creates a rate limiter instance
     *
     * @param  RateLimiterType  $type           The strategy type
     * @param  CacheInterface   $cache_adapter  Adapter for persistence (if needed)
     * @return RateLimiterInterface             The created limiter
     */
    public static function create(RateLimiterType $type, CacheInterface $cache_adapter) : RateLimiterInterface
    {
        return match($type) {
            RateLimiterType::TokenBucket => new TokenBucketRateLimiter(),
            RateLimiterType::InMemory => new InMemoryRateLimiter(),
            RateLimiterType::Symfony => throw new \InvalidArgumentException("Symfony Rate Limiter requires explicit configuration"),
            default => new InMemoryRateLimiter(),
        };
    }
}
