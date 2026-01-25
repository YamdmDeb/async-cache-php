<?php

namespace Fyennyi\AsyncCache\RateLimiter;

use Fyennyi\AsyncCache\Enum\RateLimiterType;
use Psr\SimpleCache\CacheInterface;

/**
 * Factory for creating rate limiters
 */
class RateLimiterFactory
{
    /**
     * Create a rate limiter instance
     *
     * @param  RateLimiterType  $type  Type of rate limiter
     * @param  CacheInterface|null  $cache  Optional cache for persistent storage
     * @return RateLimiterInterface
     */
    public static function create(RateLimiterType $type = RateLimiterType::InMemory, ?CacheInterface $cache = null) : RateLimiterInterface
    {
        return match ($type) {
            RateLimiterType::Symfony => new SymfonyRateLimiter(),
            RateLimiterType::InMemory => new InMemoryRateLimiter(),
            RateLimiterType::Auto => self::createBest($cache),
        };
    }

    /**
     * Create the best available rate limiter
     * 
     * @param  CacheInterface|null  $cache  Optional cache for persistent storage
     * @return RateLimiterInterface
     */
    public static function createBest(?CacheInterface $cache = null) : RateLimiterInterface
    {
        // Try Symfony first (most robust)
        if (class_exists('\Symfony\Component\RateLimiter\RateLimiterFactory')) {
            return new SymfonyRateLimiter();
        }

        // Fallback to in-memory
        return new InMemoryRateLimiter();
    }
}
