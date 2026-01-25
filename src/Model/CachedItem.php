<?php

namespace Fyennyi\AsyncCache\Model;

/**
 * Value object representing a cached item with its metadata
 */
class CachedItem
{
    public const CURRENT_VERSION = 1;

    /**
     * @param array<string, string> $tagVersions Map of tag names to their versions at the moment of caching
     */
    public function __construct(
        public readonly mixed $data,
        public readonly int $logicalExpireTime,
        public readonly int $version = self::CURRENT_VERSION,
        public readonly bool $isCompressed = false,
        public readonly float $generationTime = 0.0,
        public readonly array $tagVersions = []
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
