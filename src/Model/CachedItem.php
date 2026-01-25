<?php

namespace Fyennyi\AsyncCache\Model;

/**
 * Value object representing a cached item with its metadata
 */
class CachedItem
{
    public const CURRENT_VERSION = 1;

    public function __construct(
        public readonly mixed $data,
        public readonly int $logicalExpireTime,
        public readonly int $version = self::CURRENT_VERSION,
        public readonly bool $isCompressed = false,
        public readonly float $generationTime = 0.0
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