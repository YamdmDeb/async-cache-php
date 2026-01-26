<?php

namespace Fyennyi\AsyncCache\Core;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Model\CachedItem;

/**
 * Data Transfer Object that carries the state of a single cache resolution through the middleware pipeline
 */
class CacheContext
{
    /** @var CachedItem|null Stale item found during lookup (if any) */
    public ?CachedItem $staleItem = null;

    /** @var float Microtime when the resolution started */
    public float $startTime;

    /** @var Future|null The final result of the pipeline */
    public ?Future $resultFuture = null;

    /**
     * @param  string        $key             The cache key
     * @param  mixed         $promiseFactory  Callback to fetch fresh data
     * @param  CacheOptions  $options         Resolved caching options
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $promiseFactory,
        public readonly CacheOptions $options
    ) {
        $this->startTime = microtime(true);
    }
}
