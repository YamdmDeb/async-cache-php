<?php

namespace Fyennyi\AsyncCache\RateLimiter;

/**
 * Simple in-memory rate limiter for single-process environments
 */
class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, int> Map of key => request_count */
    private array $hits = [];

    /**
     * @param  int  $maxHits  Maximum allowed requests (not time-bound in this simple version)
     */
    public function __construct(private int $maxHits = 100) {}

    /**
     * Checks if limited
     *
     * @param  string  $key  Key to check
     * @return bool
     */
    public function isLimited(string $key) : bool
    {
        return ($this->hits[$key] ?? 0) >= $this->maxHits;
    }

    /**
     * Increments hit count
     *
     * @param  string  $key  Key to record
     * @return void
     */
    public function recordExecution(string $key) : void
    {
        $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;
    }

    /**
     * Clears specific or all hits
     *
     * @param  string|null  $key  Optional key
     * @return void
     */
    public function clear(?string $key = null) : void
    {
        if ($key) {
            unset($this->hits[$key]);
        } else {
            $this->hits = [];
        }
    }
}
