<?php

namespace Fyennyi\AsyncCache\RateLimiter;

use Psr\SimpleCache\CacheInterface;

/**
 * Factory for creating rate limiters
 */
class RateLimiterFactory
{
    /**
     * Create a rate limiter instance
     *
     * @param  string  $type  Type of rate limiter ('in_memory', 'symfony')
     * @param  CacheInterface|null  $cache  Optional cache for persistent storage
     * @return RateLimiterInterface
     */
    public static function create(string $type = 'in_memory', ?CacheInterface $cache = null) : RateLimiterInterface
    {
        return match ($type) {
            'symfony' => new SymfonyRateLimiter(),
            'in_memory' => new InMemoryRateLimiter(),
            default => throw new \InvalidArgumentException("Unknown rate limiter type: {$type}")
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
