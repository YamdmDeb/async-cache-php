# API Reference

Detailed information about the main classes and methods in the Async Cache PHP library.

## `AsyncCacheManager`

The primary entry point for caching operations.

### `__construct`
```php
public function __construct(
    CacheInterface $cache, 
    RateLimiterInterface $rateLimiter
)
```

### `wrap`
```php
public function wrap(
    string $key, 
    callable $factory, 
    CacheOptions $options
): PromiseInterface
```

## `CacheOptions`

Configuration object for individual requests.

| Property | Type | Description |
| :--- | :--- | :--- |
| `ttl` | `int` | Logical expiration in seconds. |
| `rate_limit_key` | `?string` | Key for rate limiting logic. |
| `serve_stale_if_limited`| `bool` | Return stale data on rate limit hits. |
| `stale_grace_period` | `int` | How long to keep stale data (default: 24h). |
| `force_refresh` | `bool` | Bypass cache completely. |
| `tags` | `array` | Tags for invalidation (if supported). |

## `RateLimiterInterface`

Interface for custom rate limiter implementations.

### `isLimited`
```php
public function isLimited(string $key): bool
```

### `configure`
```php
public function configure(string $key, int $interval): void
```
