<?php

namespace Fyennyi\AsyncCache\RateLimiter;

/**
 * Token bucket rate limiter implementation
 */
class TokenBucketRateLimiter implements RateLimiterInterface
{
    /** @var array  State of all buckets (tokens and last refill time) */
    private array $tokens = [];

    /**
     * @param  int  $capacity        Maximum tokens the bucket can hold
     * @param  int  $refillRate      How many tokens to add per interval
     * @param  int  $refillInterval  Seconds between refills
     */
    public function __construct(
        private int $capacity = 100,
        private int $refillRate = 10,
        private int $refillInterval = 1
    ) {
    }

    /**
     * Checks if the key has tokens available
     *
     * @param  string  $key  Resource key
     * @return bool          True if limited (no tokens)
     */
    public function isLimited(string $key) : bool
    {
        return ! $this->allow($key, 1);
    }

    /**
     * @param  string  $key  Resource key
     * @return void
     */
    public function recordExecution(string $key) : void
    {
        // consumption already happens in isLimited via allow()
    }

    /**
     * Reconfigures the bucket for a specific interval
     *
     * @param  string  $key      Resource key
     * @param  int     $seconds  New interval in seconds
     * @return void
     */
    public function configure(string $key, int $seconds) : void
    {
        $this->refillInterval = max(1, $seconds);
        if ($seconds > 0) {
            $this->refillRate = (int) max(1, intdiv($this->capacity, $seconds));
        }
    }

    /**
     * Resets the bucket state
     *
     * @param  string|null  $key  Optional key to clear
     * @return void
     */
    public function clear(?string $key = null) : void
    {
        if ($key === null) {
            $this->tokens = [];
        } else {
            unset($this->tokens[$key]);
        }
    }

    /**
     * Returns current unix timestamp
     *
     * @return int
     */
    private function now() : int
    {
        return time();
    }

    /**
     * Internal method to consume tokens and refill the bucket
     *
     * @param  string  $key    Resource key
     * @param  int     $limit  Tokens to consume
     * @return bool            True if tokens were available and consumed
     */
    public function allow(string $key, int $limit = 1) : bool
    {
        $now = $this->now();
        if (! isset($this->tokens[$key])) {
            $this->tokens[$key] = ['tokens' => $this->capacity, 'last_refill' => $now];
        }
        $bucket = &$this->tokens[$key];

        $timeElapsed = $now - $bucket['last_refill'];
        $tokensToAdd = ($timeElapsed / $this->refillInterval) * $this->refillRate;
        $bucket['tokens'] = min($this->capacity, $bucket['tokens'] + $tokensToAdd);
        $bucket['last_refill'] = $now;

        if ($bucket['tokens'] >= $limit) {
            $bucket['tokens'] -= $limit;
            return true;
        }
        return false;
    }

    /**
     * Peeks at available tokens for a key
     *
     * @param  string  $key  Resource key
     * @return int           Number of tokens currently in bucket
     */
    public function getAvailableTokens(string $key) : int
    {
        if (! isset($this->tokens[$key])) {
            return $this->capacity;
        }
        $now = $this->now();
        $bucket = &$this->tokens[$key];
        $timeElapsed = $now - $bucket['last_refill'];
        $tokensToAdd = ($timeElapsed / $this->refillInterval) * $this->refillRate;
        $bucket['tokens'] = min($this->capacity, $bucket['tokens'] + $tokensToAdd);
        $bucket['last_refill'] = $now;
        return (int) $bucket['tokens'];
    }
}
