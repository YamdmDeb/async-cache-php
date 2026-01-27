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

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Enum\CacheStrategy;

/**
 * Options DTO for AsyncCache wrapping
 */
class CacheOptions
{
    /**
     * @param  int|null       $ttl                     Logical Time-To-Live in seconds (how long data is considered fresh)
     * @param  int            $stale_grace_period      How long to keep stale data physically in cache after logical TTL expires (default: 24h)
     * @param  bool           $serve_stale_if_limited  If true, returns stale data instead of rejection when rate limit is hit
     * @param  CacheStrategy  $strategy                Caching strategy (Strict, Background, ForceRefresh)
     * @param  bool           $compression             Whether to compress data before storing
     * @param  int            $compression_threshold   Minimum data size in bytes to trigger compression
     * @param  bool           $fail_safe               If true, catch cache adapter exceptions and treat as misses
     * @param  float          $x_fetch_beta            Beta coefficient for X-Fetch algorithm (0 to disable)
     * @param  string|null    $rate_limit_key          Key for rate limiting grouping (e.g. 'alerts_api')
     * @param  string[]       $tags                    Tags for cache invalidation (if supported by cache adapter)
     */
    public function __construct(
        public ?int $ttl = 3600,
        public int $stale_grace_period = 86400,
        public bool $serve_stale_if_limited = true,
        public CacheStrategy $strategy = CacheStrategy::Strict,
        public bool $compression = false,
        public int $compression_threshold = 1024,
        public bool $fail_safe = true,
        public float $x_fetch_beta = 1.0,
        public ?string $rate_limit_key = null,
        public array $tags = []
    ) {
    }
}
