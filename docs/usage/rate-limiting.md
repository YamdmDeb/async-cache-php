# Rate Limiting

The library integrates with **Symfony Rate Limiter** to manage how often the data source is contacted when cache entries expire.

## Integration

Instead of implementing a custom interface, you can use any implementation of `Symfony\Component\RateLimiter\LimiterInterface`.

### Example with Symfony Rate Limiter

```php
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

$factory = new RateLimiterFactory([
    'id' => 'my_api',
    'policy' => 'token_bucket',
    'limit' => 5,
    'rate' => ['interval' => '10 seconds'],
], new InMemoryStorage());

$limiter = $factory->create();

$manager = new AsyncCacheManager(
    AsyncCacheManager::configure($cache)
        ->withRateLimiter($limiter)
        ->build()
);
```

## How It Interacts with Cache

When a cache item is stale and a refresh is needed:
1. The manager checks if a `rate_limit_key` is provided in `CacheOptions`.
2. It calls `$limiter->consume(1)`.
3. If **Accepted**: The factory function is called to fetch fresh data.
4. If **Rejected**: 
    - If `serve_stale_if_limited` is **true** and stale data exists, the stale data is returned.
    - Otherwise, a `RateLimitException` is thrown.

## Benefit of Symfony Integration

By using Symfony Rate Limiter, you gain access to various storage backends (Redis, Database, PHP-APC) and sophisticated policies (Token Bucket, Fixed Window, Sliding Window) without additional configuration in this library.
