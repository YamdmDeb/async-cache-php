<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\RateLimiter\RateLimiterFactory;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Configuration for AsyncCacheManager
 */
class AsyncCacheConfig
{
    public function __construct(
        public readonly CacheInterface $cache,
        public readonly string $rateLimiterType = 'auto',
        public readonly ?array $rateLimiterOptions = null
    ) {}

    /**
     * Create rate limiter based on configuration
     */
    public function createRateLimiter() : RateLimiterInterface
    {
        return match ($this->rateLimiterType) {
            'symfony' => RateLimiterFactory::create('symfony', $this->cache),
            'in_memory' => RateLimiterFactory::create('in_memory', $this->cache),
            'auto' => RateLimiterFactory::createBest($this->cache),
            default => throw new \InvalidArgumentException("Unknown rate limiter type: {$this->rateLimiterType}")
        };
    }
}
