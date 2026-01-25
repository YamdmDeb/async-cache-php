<?php

namespace Fyennyi\AsyncCache\Model;

/**
 * Value object representing a cached item with its metadata
 */
class CachedItem
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $logicalExpireTime,
        public readonly int $version = 1
    ) {
    }

    /**
     * Checks if the item is still logically fresh
     */
    public function isFresh() : bool
    {
        return time() < $this->logicalExpireTime;
    }
}
