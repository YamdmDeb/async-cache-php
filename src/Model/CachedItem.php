<?php

namespace Fyennyi\AsyncCache\Model;

/**
 * Value object representing a cached item with its metadata
 */
class CachedItem
{
    public const CURRENT_VERSION = 1;

    /**
     * @param  mixed   $data               The actual cached value
     * @param  int     $logicalExpireTime  Unix timestamp when the data becomes stale
     * @param  int     $version            Metadata schema version
     * @param  bool    $isCompressed       Whether the data is currently gzipped
     * @param  float   $generationTime     Duration in seconds taken to fetch this data
     * @param  array   $tagVersions        Map of tag names to their versions at caching time
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
     *
     * @return bool True if current time is before logical expiry
     */
    public function isFresh() : bool
    {
        return time() < $this->logicalExpireTime;
    }
}
