<?php

namespace Fyennyi\AsyncCache\RateLimiter;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Symfony Rate Limiter adapter for AsyncCache
 */
class SymfonyRateLimiter implements RateLimiterInterface
{
    private StorageInterface $storage;
    private array $limiters = [];
    private array $config = [];

    public function __construct(?StorageInterface $storage = null)
    {
        $this->storage = $storage ?? new InMemoryStorage();
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

        $limiter = $this->limiters[$key];
        $limiter->consume(1);
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

        // Create a dedicated factory for this key with specific configuration
        $factory = new RateLimiterFactory([
            'id' => $key,
            'policy' => 'token_bucket',
            'limit' => 1,
            'rate' => ['interval' => $seconds . ' seconds'],
        ], $this->storage);

        $this->limiters[$key] = $factory->create();
    }
}