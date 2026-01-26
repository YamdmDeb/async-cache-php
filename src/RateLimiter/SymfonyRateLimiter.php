<?php

namespace Fyennyi\AsyncCache\RateLimiter;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Adapter for Symfony Rate Limiter component
 */
class SymfonyRateLimiter implements RateLimiterInterface
{
    /** @var StorageInterface  Storage for rate limiter states */
    private StorageInterface $storage;

    /** @var array  Map of active limiter instances */
    private array $limiters = [];

    /** @var array  Configuration storage for keys */
    private array $config = [];

    /**
     * @param  StorageInterface|null  $storage  Symfony Limiter Storage
     */
    public function __construct(?StorageInterface $storage = null)
    {
        $this->storage = $storage ?? new InMemoryStorage();
    }

    /**
     * Checks if the given key has exceeded its rate limit
     *
     * @param  string  $key  Resource key to check
     * @return bool          True if limited
     */
    public function isLimited(string $key) : bool
    {
        if (! isset($this->limiters[$key])) {
            return false;
        }

        $limiter = $this->limiters[$key];

        // Try to consume 1 token - if not available, we're rate limited
        return !$limiter->consume(1)->isAccepted();
    }

    /**
     * Records a successful execution attempt
     *
     * @param  string  $key  Resource key
     * @return void
     */
    public function recordExecution(string $key) : void
    {
        if (! isset($this->limiters[$key])) {
            return;
        }

        $limiter = $this->limiters[$key];
        $limiter->consume(1);
    }

    /**
     * Clears rate limit state for a specific key or entirely
     *
     * @param  string|null  $key  Optional key to clear
     * @return void
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
     *
     * @param  string  $key  Resource key
     * @return int           Configured interval in seconds
     */
    public function getInterval(string $key) : int
    {
        return $this->config[$key] ?? 0;
    }

    /**
     * Configures a specific rate limit for a key
     *
     * @param  string  $key      Resource key
     * @param  int     $seconds  Interval in seconds
     * @return void
     */
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
