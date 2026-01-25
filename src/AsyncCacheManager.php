<?php

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Exception\RateLimitException;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterFactory;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\SimpleCache\CacheInterface;

class AsyncCacheManager
{
    /**
     * Wrapper struct to store data and logical expiration separate from physical cache TTL
     */
    private const KEY_DATA = 'd';
    private const KEY_LOGICAL_EXPIRE = 'e';

    /**
     * @param  CacheInterface  $cache_adapter  The PSR-16 cache implementation
     * @param  RateLimiterInterface|null  $rate_limiter  The rate limiter implementation
     * @param  string  $rate_limiter_type  Type of rate limiter to use
     */
    public function __construct(
        private CacheInterface $cache_adapter,
        private ?RateLimiterInterface $rate_limiter = null,
        private string $rate_limiter_type = 'auto'
    ) {
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
            $cached_item = $this->cache_adapter->get($key);
        }

        // 2. Check if cache is fresh (Hit)
        if ($cached_item !== null && is_array($cached_item)) {
            $logical_expire_at = $cached_item[self::KEY_LOGICAL_EXPIRE] ?? 0;

            if (time() < $logical_expire_at) {
                // Data is strictly fresh, return immediately
                return Create::promiseFor($cached_item[self::KEY_DATA]);
            }
        }

        // 3. Cache is missed or stale (Expired). We need to decide whether to fetch or use stale.
        $is_rate_limited = false;
        if ($options->rate_limit_key) {
            $is_rate_limited = $this->rate_limiter->isLimited($options->rate_limit_key);
        }

        // 4. Stale Fallback Strategy
        if ($is_rate_limited) {
            // We are limited. Can we return stale data?
            if ($options->serve_stale_if_limited && $cached_item !== null) {
                // Yes, return stale data instead of failing
                return Create::promiseFor($cached_item[self::KEY_DATA]);
            }

            // Cannot serve stale (or no stale data exists) -> Fail
            return Create::rejectionFor(new RateLimitException($options->rate_limit_key));
        }

        // 5. Execute actual request
        if ($options->rate_limit_key) {
            $this->rate_limiter->recordExecution($options->rate_limit_key);
        }

        return $promise_factory()->then(
            function ($data) use ($key, $options) {
                $this->storeInCache($key, $data, $options);
                return $data;
            }
        );
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

        $wrapper = [
            self::KEY_DATA => $data,
            self::KEY_LOGICAL_EXPIRE => time() + $logical_ttl,
        ];

        // Note: PSR-16 SimpleCache doesn't strictly support tags in the interface,
        // but some adapters (like Symfony's) extend it or handle it via configuration.
        // Since we depend on strict PSR-16 here, we just use set().
        // If advanced tagging is needed, we might need to check instanceof or use a specific adapter.

        $this->cache_adapter->set($key, $wrapper, $physical_ttl);
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
