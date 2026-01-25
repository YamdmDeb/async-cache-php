<?php

namespace Fyennyi\AsyncCache\Event;

/**
 * Base class for all AsyncCache events
 */
abstract class AsyncCacheEvent
{
    public function __construct(
        public readonly string $key,
        public readonly float $timestamp
    ) {
    }
}
