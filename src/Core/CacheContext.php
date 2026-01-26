<?php

namespace Fyennyi\AsyncCache\Core;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Model\CachedItem;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Carries the state of a single cache resolution through the middleware pipeline
 */
class CacheContext
{
    public ?CachedItem $staleItem = null;
    public float $startTime;
    public ?PromiseInterface $resultPromise = null;

    public function __construct(
        public readonly string $key,
        public readonly mixed $promiseFactory,
        public readonly CacheOptions $options
    ) {
        $this->startTime = microtime(true);
    }
}
