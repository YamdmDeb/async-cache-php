<?php

namespace Fyennyi\AsyncCache\RateLimiter;

/**
 * Simple in-memory rate limiter based on minimum interval between requests
 * Useful for scripts or single-process applications
 */
class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, int> Stores the timestamp of the last execution */
    private array $last_execution_times = [];

    /** @var array<string, int> Configured minimum intervals in seconds per key */
    private array $intervals = [];

    /**
     * @param  array<string, int>  $default_intervals  Map of key => interval_in_seconds
     */
    public function __construct(array $default_intervals = [])
    {
        $this->intervals = $default_intervals;
    }

    /**
     * Configures the rate limit interval for a specific key
     *
     * @param  string  $key  The rate limit key
     * @param  int  $interval_seconds  Minimum seconds between requests
     * @return void
     */
    public function configure(string $key, int $interval_seconds) : void
    {
        $this->intervals[$key] = $interval_seconds;
    }

    public function isLimited(string $key) : bool
    {
        if (!isset($this->last_execution_times[$key])) {
            return false;
        }

        $interval = $this->intervals[$key] ?? 0;
        $time_passed = time() - $this->last_execution_times[$key];

        return $time_passed < $interval;
    }

    public function recordExecution(string $key) : void
    {
        $this->last_execution_times[$key] = time();
    }
}
