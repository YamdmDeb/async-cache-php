# Caching Strategies

Async Cache PHP offers different strategies to handle cache misses and stale data. These strategies are defined in the `Fyennyi\AsyncCache\Enum\CacheStrategy` enum and configured via `CacheOptions`.

## Available Strategies

### 1. Strict Strategy (`CacheStrategy::Strict`)

This is the default strategy. It prioritizes data consistency.

- **Behavior**:
    - If data is **fresh**: Returns cached data immediately.
    - If data is **stale** or **missing**: Waits for the factory function to complete and returns the fresh data.
- **Use Case**: When you absolutely need the latest data and cannot tolerate stale content.

```php
use Fyennyi\AsyncCache\Enum\CacheStrategy;

new CacheOptions(
    strategy: CacheStrategy::Strict,
    ttl: 60
);
```

### 2. Background Strategy (`CacheStrategy::Background`)

This strategy implements the **Stale-While-Revalidate** pattern. It prioritizes speed and responsiveness.

- **Behavior**:
    - If data is **fresh**: Returns cached data immediately.
    - If data is **stale** (but exists physically): Returns the stale data **immediately** and triggers a background refresh.
    - If data is **missing**: Waits for the factory function to complete.
- **Use Case**: High-traffic endpoints where speed is critical, and slightly outdated data is acceptable (e.g., news feeds, product lists).

```php
new CacheOptions(
    strategy: CacheStrategy::Background,
    ttl: 60,
    stale_grace_period: 3600 // Keep stale data for 1 hour
);
```

### 3. Force Refresh (`CacheStrategy::ForceRefresh`)

This strategy bypasses the cache lookup.

- **Behavior**: Always executes the factory function and updates the cache with the new result.
- **Use Case**: When the user explicitly requests a refresh (e.g., "Pull to Refresh" in UI).

```php
new CacheOptions(
    strategy: CacheStrategy::ForceRefresh,
    ttl: 60
);
```

## Interaction with Rate Limits

When using `CacheStrategy::Strict` combined with Rate Limiting:

1. If the rate limit is exceeded, the manager checks if stale data is available.
2. If `serve_stale_if_limited` is set to `true`, it falls back to returning stale data instead of throwing an exception.

This provides a safety net for your application during high load or API outages.
