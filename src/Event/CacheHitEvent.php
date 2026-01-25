<?php

namespace Fyennyi\AsyncCache\Event;

class CacheHitEvent extends AsyncCacheEvent
{
    public function __construct(string $key, public readonly mixed $data)
    {
        parent::__construct($key, microtime(true));
    }
}
