<?php

namespace Fyennyi\AsyncCache\Exception;

/**
 * Exception thrown when a request is blocked by the rate limiter
 */
class RateLimitException extends \RuntimeException
{
    /**
     * @param  string  $key  The rate limit key that was exceeded
     */
    public function __construct(string $key)
    {
        parent::__construct(sprintf("Rate limit exceeded for key: %s", $key));
    }
}