<?php

/*
 *
 *     _                          ____           _            ____  _   _ ____
 *    / \   ___ _   _ _ __   ___ / ___|__ _  ___| |__   ___  |  _ \| | | |  _ \
 *   / _ \ / __| | | | '_ \ / __| |   / _` |/ __| '_ \ / _ \ | |_) | |_| | |_) |
 *  / ___ \\__ \ |_| | | | | (__| |__| (_| | (__| | | |  __/ |  __/|  _  |  __/
 * /_/   \_\___/\__, |_| |_|\___|\____\__,_|\___|_| |_|\___| |_|   |_| |_|_|
 *              |___/
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author Serhii Cherneha
 * @link https://chernega.eu.org/
 *
 *
 */

namespace Fyennyi\AsyncCache\Core;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Model\CachedItem;

/**
 * Data Transfer Object that carries the state of a single cache resolution through the middleware pipeline
 */
class CacheContext
{
    /** @var CachedItem|null Stale item found during lookup (if any) */
    public ?CachedItem $stale_item = null;

    /** @var float Microtime when the resolution started */
    public float $start_time;

    /** @var Future|null The final result of the pipeline */
    public ?Future $result_future = null;

    /**
     * @param  string        $key              The cache key
     * @param  mixed         $promise_factory  Callback to fetch fresh data
     * @param  CacheOptions  $options          Resolved caching options
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $promise_factory,
        public readonly CacheOptions $options
    ) {
        $this->start_time = microtime(true);
    }
}
