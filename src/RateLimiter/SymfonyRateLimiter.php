<?php

namespace Fyennyi\AsyncCache\RateLimiter;

use Symfony\Component\RateLimiter\LimiterRateInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Symfony Rate Limiter adapter for AsyncCache
 */
class SymfonyRateLimiter implements RateLimiterInterface
{
    private RateLimiterFactory $factory;
    private array $limiters = [];
    private array $config = [];

    public function __construct(?StorageInterface $storage = null)
    {
        // Use in-memory storage by default
        $this->factory = new RateLimiterFactory([], $storage);
    }

    public function isLimited(string $key) : bool
    {
        if (! isset($this->limiters[$key])) {
            return false;
        }

        $limiter = $this->limiters[$key];
        
        // Try to consume 1 token - if not available, we're rate limited
        return !$limiter->consume(1)->isAccepted();
    }

    public function recordExecution(string $key) : void
    {
        if (! isset($this->limiters[$key])) {
            return;
        }

        // In Symfony's implementation, consuming a token is both the check AND the record
        // We already checked/consumed in isLimited(), so this is a no-op
        // But for interface compatibility, we ensure the token is consumed
        $limiter = $this->limiters[$key];
        $limiter->consume(1)->wait(); // Ensure token is consumed and wait if needed
    }

    /**
     * Clears the rate limiter state
     * 
     * @param  string|null  $key  The key to clear, or null to clear all
     */
    public function clear(?string $key = null) : void
    {
        if ($key === null) {
            $this->limiters = [];
            $this->config = [];
            return;
        }

        unset($this->limiters[$key], $this->config[$key]);
    }

    /**
     * Gets configured interval for a key
     */
    public function getInterval(string $key) : int
    {
        return $this->config[$key] ?? 0;
    }

    public function configure(string $key, int $seconds) : void
    {
        $this->config[$key] = $seconds;
        
        // Create or update limiter for this key
        // Convert to rate format: X requests per Y seconds
        $this->limiters[$key] = $this->factory->create([
            'id' => $key,
            'policy' => 'token_bucket',
            'limit' => 1, // 1 request
            'rate' => ['interval' => $seconds . ' seconds'],
        ]);
    }
}
