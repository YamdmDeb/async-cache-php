<?php

namespace Fyennyi\AsyncCache\RateLimiter;

/**
 * Interface for rate limiting strategies
 */
interface RateLimiterInterface
{
    /**
     * Checks if the given key has exceeded its rate limit
     *
     * @param  string  $key  The key to check
     * @return bool          True if limited, false if allowed
     */
    public function isLimited(string $key) : bool;

    /**
     * Records a successful execution attempt for the given key
     *
     * @param  string  $key  The key to record
     * @return void
     */
    public function recordExecution(string $key) : void;

    /**
     * Clears rate limit state for a specific key or entirely
     *
     * @param  string|null  $key  Optional key to clear
     * @return void
     */
    public function clear(?string $key = null) : void;
}
