<?php

namespace Fyennyi\AsyncCache\Event;

class CacheMissEvent extends AsyncCacheEvent
{
    public function __construct(string $key)
    {
        parent::__construct($key, microtime(true));
    }
}
