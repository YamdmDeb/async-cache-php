<?php

namespace Fyennyi\AsyncCache\Event;

/**
 * Event dispatched when a request is blocked by the rate limiter
 */
class RateLimitExceededEvent extends AsyncCacheEvent
{
    /**
     * @param  string  $key           The resource key being requested
     * @param  string  $rateLimitKey  The identifier of the rate limit bucket
     */
    public function __construct(string $key, public readonly string $rateLimitKey)
    {
        parent::__construct($key, microtime(true));
    }
}
