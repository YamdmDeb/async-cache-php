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
 * Builder for CacheOptions DTO
 */
class CacheOptionsBuilder
{
    private ?int $ttl = 3600;
    private int $stale_grace_period = 86400;
    private bool $serve_stale_if_limited = true;
    private CacheStrategy $strategy = CacheStrategy::Strict;
    private bool $compression = false;
    private int $compression_threshold = 1024;
    private bool $fail_safe = true;
    private float $x_fetch_beta = 1.0;
    private ?string $rate_limit_key = null;
    /** @var string[] */
    private array $tags = [];

    /**
     * Entry point for the fluent builder
     *
     * @return self New builder instance
     */
    public static function create() : self
    {
        return new self();
    }

    /**
     * Sets the logical Time-To-Live
     *
     * @param  int|null  $ttl  Seconds until data is considered stale
     * @return self            Current builder instance
     */
    public function withTtl(?int $ttl) : self
    {
        $this->ttl = $ttl;
        return $this;
    }

    /**
     * Configures the physical grace period for stale data
     *
     * @param  int  $seconds  Seconds to keep data after TTL expires
     * @return self           Current builder instance
     */
    public function withStaleGracePeriod(int $seconds) : self
    {
        $this->stale_grace_period = $seconds;
        return $this;
    }

    /**
     * Sets the caching strategy
     *
     * @param  CacheStrategy  $strategy  Strategy identifier
     * @return self                      Current builder instance
     */
    public function withStrategy(CacheStrategy $strategy) : self
    {
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * Enables background revalidation strategy
     *
     * @return self Current builder instance
     */
    public function withBackgroundRefresh() : self
    {
        $this->strategy = CacheStrategy::Background;
        return $this;
    }

    /**
     * Enables force refresh strategy (bypass cache)
     *
     * @return self Current builder instance
     */
    public function withForceRefresh() : self
    {
        $this->strategy = CacheStrategy::ForceRefresh;
        return $this;
    }

    /**
     * Configures data compression
     *
     * @param  bool  $enabled    Whether to enable compression
     * @param  int   $threshold  Minimum data size in bytes to trigger compression
     * @return self              Current builder instance
     */
    public function withCompression(bool $enabled = true, int $threshold = 1024) : self
    {
        $this->compression = $enabled;
        $this->compression_threshold = $threshold;
        return $this;
    }

    /**
     * Configures fail-safe behavior
     *
     * @param  bool  $enabled  Whether to catch adapter exceptions
     * @return self            Current builder instance
     */
    public function withFailSafe(bool $enabled = true) : self
    {
        $this->fail_safe = $enabled;
        return $this;
    }

    /**
     * Configures X-Fetch probabilistic early expiration
     *
     * @param  float  $beta  Beta coefficient (0 to disable)
     * @return self          Current builder instance
     */
    public function withXFetch(float $beta = 1.0) : self
    {
        $this->x_fetch_beta = $beta;
        return $this;
    }

    /**
     * Configures rate limiting for the request
     *
     * @param  string  $key          Identifier for rate limit grouping
     * @param  bool    $serve_stale  Whether to return stale data if limited
     * @return self                  Current builder instance
     */
    public function withRateLimit(string $key, bool $serve_stale = true) : self
    {
        $this->rate_limit_key = $key;
        $this->serve_stale_if_limited = $serve_stale;
        return $this;
    }

     /**
      * Sets cache invalidation tags
      *
      * @param  string[]  $tags  List of tags
      * @return self             Current builder instance
      */
    public function withTags(array $tags) : self
    {
        $this->tags = $tags;
        return $this;
    }

    /**
     * Finalizes and builds the CacheOptions object
     *
     * @return CacheOptions Configured options instance
     */
    public function build() : CacheOptions
    {
        return new CacheOptions(
            ttl: $this->ttl,
            stale_grace_period: $this->stale_grace_period,
            serve_stale_if_limited: $this->serve_stale_if_limited,
            strategy: $this->strategy,
            compression: $this->compression,
            compression_threshold: $this->compression_threshold,
            fail_safe: $this->fail_safe,
            x_fetch_beta: $this->x_fetch_beta,
            rate_limit_key: $this->rate_limit_key,
            tags: $this->tags
        );
    }
}
