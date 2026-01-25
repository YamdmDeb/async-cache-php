<?php

namespace Fyennyi\AsyncCache;

/**
 * Configuration options for a specific cacheable operation
 */
class CacheOptions
{
    /**
     * @param  int|null  $ttl  Logical Time-To-Live in seconds (how long data is considered fresh)
     * @param  string|null  $rate_limit_key  Key for rate limiting grouping (e.g. 'nominatim_search', 'alerts_api')
     * @param  bool  $serve_stale_if_limited  If true, returns stale data instead of rejection when rate limit is hit
     * @param  int  $stale_grace_period  How long to keep stale data physically in cache after logical TTL expires (default: 24h)
     * @param  bool  $force_refresh  If true, ignores existing fresh cache and forces a new request
     * @param  array  $tags  Tags for cache invalidation (if supported by cache adapter)
     */
    public function __construct(
        public ?int $ttl = 3600,
        public int $stale_grace_period = 86400,
        public bool $serve_stale_if_limited = true,
        public bool $background_refresh = false,
        public ?string $rate_limit_key = null,
        public bool $force_refresh = false
    ) {
    }
}