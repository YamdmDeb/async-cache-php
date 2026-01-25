<?php

namespace Fyennyi\AsyncCache\Event;

class RateLimitExceededEvent extends AsyncCacheEvent
{
    public function __construct(string $key, public readonly string $rateLimitKey)
    {
        parent::__construct($key, microtime(true));
    }
}
