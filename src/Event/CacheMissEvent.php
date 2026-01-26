<?php

namespace Fyennyi\AsyncCache\Event;

/**
 * Event dispatched when requested data is not found in cache
 */
class CacheMissEvent extends AsyncCacheEvent
{
    /**
     * @param  string  $key  Resource identifier
     */
    public function __construct(string $key)
    {
        parent::__construct($key, microtime(true));
    }
}
