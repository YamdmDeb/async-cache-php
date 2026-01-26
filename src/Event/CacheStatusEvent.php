<?php

namespace Fyennyi\AsyncCache\Event;

use Fyennyi\AsyncCache\Enum\CacheStatus;

/**
 * Event dispatched for every cache resolution attempt to support metrics and telemetry
 */
class CacheStatusEvent extends AsyncCacheEvent
{
    /**
     * @param  string       $key      Resource identifier
     * @param  CacheStatus  $status   The resulting status (Hit, Miss, Stale, etc.)
     * @param  float        $latency  Time taken to resolve the request in seconds
     * @param  array        $tags     Cache tags associated with the entry
     */
    public function __construct(
        string $key,
        public readonly CacheStatus $status,
        public readonly float $latency = 0.0,
        public readonly array $tags = []
    ) {
        parent::__construct($key, microtime(true));
    }
}
