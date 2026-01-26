<?php

namespace Fyennyi\AsyncCache\Event;

/**
 * Event dispatched when requested data is successfully retrieved from cache
 */
class CacheHitEvent extends AsyncCacheEvent
{
    /**
     * @param  string  $key   Resource identifier
     * @param  mixed   $data  The cached data retrieved
     */
    public function __construct(string $key, public readonly mixed $data)
    {
        parent::__construct($key, microtime(true));
    }
}
