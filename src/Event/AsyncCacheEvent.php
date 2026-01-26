<?php

namespace Fyennyi\AsyncCache\Event;

/**
 * Base class for all AsyncCache events
 */
abstract class AsyncCacheEvent
{
    /**
     * @param  string  $key        The resource key associated with the event
     * @param  float   $timestamp  Unix timestamp with microseconds
     */
    public function __construct(
        public readonly string $key,
        public readonly float $timestamp
    ) {
    }
}
