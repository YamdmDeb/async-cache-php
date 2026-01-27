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
