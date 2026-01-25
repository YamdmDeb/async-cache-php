<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Exception\RateLimitException;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterFactory;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

class AsyncCacheManager
{
    /** @var array<string, PromiseInterface> */
    private array $pending_promises = [];

    /**
     * @param  CacheInterface  $cache_adapter  The PSR-16 cache implementation
     * @param  RateLimiterInterface|null  $rate_limiter  The rate limiter implementation
     * @param  string  $rate_limiter_type  Type of rate limiter to use
     * @param  LoggerInterface|null  $logger  The PSR-3 logger implementation
     */
    public function __construct(
        private CacheInterface $cache_adapter,
        private ?RateLimiterInterface $rate_limiter = null,
        private string $rate_limiter_type = 'auto',
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();

        if ($this->rate_limiter === null) {
            $this->rate_limiter = match ($this->rate_limiter_type) {
                'symfony' => RateLimiterFactory::create('symfony', $this->cache_adapter),
                'in_memory' => RateLimiterFactory::create('in_memory', $this->cache_adapter),
                'auto' => RateLimiterFactory::createBest($this->cache_adapter),
                default => throw new \InvalidArgumentException("Unknown rate limiter type: {$this->rate_limiter_type}")
            };
        }
    }

    /**
     * Wraps an asynchronous operation with caching, rate limiting, and stale-data fallback
     *
     * @param  string  $key  Unique cache key for the data
     * @param  callable(): PromiseInterface  $promise_factory  Function that returns the Promise to execute
     * @param  CacheOptions  $options  Configuration for this request
     * @return PromiseInterface
     */
    public function wrap(string $key, callable $promise_factory, CacheOptions $options) : PromiseInterface
    {
        // 1. Try to fetch from cache first
        $cached_item = null;
        if (! $options->force_refresh) {
            $cached_item = $this->safeCacheGet($key, $options);
        }

        // 2. Check if cache is fresh (Hit)
        if ($cached_item !== null) {
            // Backward compatibility: handle old array format
            if (is_array($cached_item) && array_key_exists('d', $cached_item) && array_key_exists('e', $cached_item)) {
                $cached_item = new CachedItem($cached_item['d'], $cached_item['e']);
            }

            if ($cached_item instanceof CachedItem) {
                // Handle decompression if needed
                if ($cached_item->isCompressed && is_string($cached_item->data)) {
                    $decompressed_data = @gzuncompress($cached_item->data);
                    if ($decompressed_data !== false) {
                        $data = unserialize($decompressed_data);
                        $cached_item = new CachedItem(
                            data: $data,
                            logicalExpireTime: $cached_item->logicalExpireTime,
                            version: $cached_item->version,
                            isCompressed: false
                        );
                    } else {
                        $this->logger->error('AsyncCache DECOMPRESSION_ERROR: failed to decompress data', ['key' => $key]);
                        $cached_item = null; // Treat as miss if decompression fails
                    }
                }
            }

            if ($cached_item instanceof CachedItem) {
                if ($cached_item->isFresh()) {
                    $this->logger->debug('AsyncCache HIT: fresh data returned', ['key' => $key]);
                    return Create::promiseFor($cached_item->data);
                }

                // Cache is stale. Can we do background refresh?
                if ($options->background_refresh && ! $options->force_refresh) {
                    $this->logger->info('AsyncCache STALE: triggering background refresh', ['key' => $key]);
                    
                    // Trigger refresh without waiting for it
                    $this->fetch($key, $promise_factory, $options);
                    
                    // Return stale data immediately
                    return Create::promiseFor($cached_item->data);
                }
            }
        }

        // 3. Cache is missed or stale (No background refresh or not available).
        
        // --- Promise Coalescing Start ---
        if (isset($this->pending_promises[$key])) {
            $this->logger->info('AsyncCache COALESCE: reusing pending promise', ['key' => $key]);
            return $this->pending_promises[$key];
        }
        // --- Promise Coalescing End ---

        $is_rate_limited = false;
        if ($options->rate_limit_key) {
            $is_rate_limited = $this->rate_limiter->isLimited($options->rate_limit_key);
        }

        // 4. Stale Fallback Strategy
        if ($is_rate_limited) {
            if ($options->serve_stale_if_limited && $cached_item instanceof CachedItem) {
                $this->logger->warning('AsyncCache RATE_LIMIT: serving stale data', [
                    'key' => $key,
                    'rate_limit_key' => $options->rate_limit_key
                ]);
                return Create::promiseFor($cached_item->data);
            }

            $this->logger->error('AsyncCache RATE_LIMIT: execution blocked', [
                'key' => $key,
                'rate_limit_key' => $options->rate_limit_key
            ]);
            return Create::rejectionFor(new RateLimitException($options->rate_limit_key));
        }

        // 5. Execute actual request
        $this->logger->info('AsyncCache MISS: fetching fresh data', ['key' => $key]);
        return $this->fetch($key, $promise_factory, $options);
    }

    /**
     * Internal method to fetch fresh data, handle rate limiting, caching and coalescing
     */
    private function fetch(string $key, callable $promise_factory, CacheOptions $options) : PromiseInterface
    {
        if (isset($this->pending_promises[$key])) {
            return $this->pending_promises[$key];
        }

        if ($options->rate_limit_key) {
            $this->rate_limiter->recordExecution($options->rate_limit_key);
        }

        $promise = $promise_factory()->then(
            function ($data) use ($key, $options) {
                unset($this->pending_promises[$key]);
                $this->storeInCache($key, $data, $options);
                return $data;
            },
            function ($reason) use ($key) {
                unset($this->pending_promises[$key]);
                $this->logger->error('AsyncCache FETCH_ERROR: failed to fetch fresh data', [
                    'key' => $key,
                    'reason' => $reason
                ]);
                throw $reason;
            }
        );

        return $this->pending_promises[$key] = $promise;
    }

    /**
     * Stores the result in the cache with physical and logical TTLs
     *
     * @param  string  $key  Cache key
     * @param  mixed  $data  Data to store
     * @param  CacheOptions  $options  Cache options containing TTL configuration
     * @return void
     */
    private function storeInCache(string $key, mixed $data, CacheOptions $options) : void
    {
        if ($options->ttl === null) {
            return; // No caching requested
        }

        $logical_ttl = $options->ttl;
        // Physical TTL = Logical TTL + Grace Period (to allow serving stale data)
        $physical_ttl = $logical_ttl + $options->stale_grace_period;

        $is_compressed = false;
        if ($options->compression) {
            $serialized_data = serialize($data);
            if (strlen($serialized_data) >= $options->compression_threshold) {
                $compressed_data = @gzcompress($serialized_data);
                if ($compressed_data !== false) {
                    $data = $compressed_data;
                    $is_compressed = true;
                    $this->logger->debug('AsyncCache COMPRESSION: data compressed', [
                        'key' => $key,
                        'original_size' => strlen($serialized_data),
                        'compressed_size' => strlen($compressed_data)
                    ]);
                }
            }
        }

        $wrapper = new CachedItem(
            data: $data,
            logicalExpireTime: time() + $logical_ttl,
            isCompressed: $is_compressed
        );

        $this->safeCacheSet($key, $wrapper, $physical_ttl, $options);
    }

    /**
     * PSR-16 Get with Fail-Safe protection
     */
    private function safeCacheGet(string $key, CacheOptions $options): mixed
    {
        try {
            return $this->cache_adapter->get($key);
        } catch (\Throwable $e) {
            if ($options->fail_safe) {
                $this->logger->error('AsyncCache CACHE_GET_ERROR: adapter failed, using fail-safe miss', [
                    'key' => $key,
                    'exception' => $e->getMessage()
                ]);
                return null;
            }
            throw $e;
        }
    }

    /**
     * PSR-16 Set with Fail-Safe protection
     */
    private function safeCacheSet(string $key, mixed $value, ?int $ttl, CacheOptions $options): bool
    {
        try {
            return $this->cache_adapter->set($key, $value, $ttl);
        } catch (\Throwable $e) {
            if ($options->fail_safe) {
                $this->logger->error('AsyncCache CACHE_SET_ERROR: adapter failed', [
                    'key' => $key,
                    'exception' => $e->getMessage()
                ]);
                return false;
            }
            throw $e;
        }
    }

    /**
     * Wipes the entire cache's keys
     *
     * @return bool True on success and false on failure
     */
    public function clear() : bool
    {
        return $this->cache_adapter->clear();
    }

    /**
     * Delete an item from the cache by its unique key
     *
     * @param  string  $key  The unique cache key of the item to delete
     * @return bool True if the item was successfully removed, false if there was an error
     */
    public function delete(string $key) : bool
    {
        return $this->cache_adapter->delete($key);
    }

    /**
     * Returns the rate limiter instance
     *
     * @return RateLimiterInterface
     */
    public function getRateLimiter() : RateLimiterInterface
    {
        return $this->rate_limiter;
    }

    /**
     * Clears the rate limiter state
     * 
     * @param string|null $key The key to clear, or null to clear all
     */
    public function clearRateLimiter(?string $key = null) : void
    {
        // Check if rate limiter supports clearing
        if (method_exists($this->rate_limiter, 'clear')) {
            $this->rate_limiter->clear($key);
        }
    }
}