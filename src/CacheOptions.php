<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Enum\CacheStrategy;

/**
 * Options DTO for AsyncCache wrapping
 */
class CacheOptions
{
    /**
     * @param  int|null  $ttl  Logical Time-To-Live in seconds (how long data is considered fresh)
     * @param  int  $stale_grace_period  How long to keep stale data physically in cache after logical TTL expires (default: 24h)
     * @param  bool  $serve_stale_if_limited  If true, returns stale data instead of rejection when rate limit is hit
     * @param  CacheStrategy  $strategy  Caching strategy (Strict, Background, ForceRefresh)
     * @param  bool  $compression  Whether to compress data before storing
     * @param  int  $compression_threshold  Minimum data size in bytes to trigger compression
     * @param  bool  $fail_safe  If true, catch cache adapter exceptions and treat as misses
     * @param  float  $x_fetch_beta  Beta coefficient for X-Fetch algorithm (0 to disable)
     * @param  string|null  $rate_limit_key  Key for rate limiting grouping (e.g. 'alerts_api')
     * @param  array  $tags  Tags for cache invalidation (if supported by cache adapter)
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

    /**
     * Static factory for fluent interface
     */
    public static function create(): self
    {
        return new self();
    }

    public function withTtl(?int $ttl): self
    {
        $this->ttl = $ttl;
        return $this;
    }

    public function withStaleGracePeriod(int $seconds): self
    {
        $this->stale_grace_period = $seconds;
        return $this;
    }

    public function withStrategy(CacheStrategy $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    public function withBackgroundRefresh(): self
    {
        $this->strategy = CacheStrategy::Background;
        return $this;
    }

    public function withForceRefresh(): self
    {
        $this->strategy = CacheStrategy::ForceRefresh;
        return $this;
    }

    public function withCompression(bool $enabled = true, int $threshold = 1024): self
    {
        $this->compression = $enabled;
        $this->compression_threshold = $threshold;
        return $this;
    }

    public function withFailSafe(bool $enabled = true): self
    {
        $this->fail_safe = $enabled;
        return $this;
    }

    public function withXFetch(float $beta = 1.0): self
    {
        $this->x_fetch_beta = $beta;
        return $this;
    }

    public function withRateLimit(string $key, bool $serveStale = true): self
    {
        $this->rate_limit_key = $key;
        $this->serve_stale_if_limited = $serveStale;
        return $this;
    }

    public function withTags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }
}
