<?php

namespace Fyennyi\AsyncCache\RateLimiter;

class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, float> */
    private array $last_execution_time = [];

    /** @var array<string, int> */
    private array $intervals = [];

    public function configure(string $key, int $seconds): void
    {
        $this->intervals[$key] = $seconds;
    }

    public function isLimited(string $key): bool
    {
        if (!isset($this->intervals[$key])) {
            return false;
        }

        $interval = $this->intervals[$key];
        
        // If interval is 0, we consider it disabled (unlimited)
        if ($interval === 0) {
            return false;
        }

        if (!isset($this->last_execution_time[$key])) {
            return false;
        }

        $elapsed = microtime(true) - $this->last_execution_time[$key];

        return $elapsed < $interval;
    }

    public function recordExecution(string $key): void
    {
        $this->last_execution_time[$key] = microtime(true);
    }
}