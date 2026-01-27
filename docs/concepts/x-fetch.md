# X-Fetch & Stampede Protection

One of the most difficult problems in caching is the **Cache Stampede** (also known as the dog-pile effect). This happens when a frequently accessed cache item expires, and multiple requests try to regenerate it simultaneously, crashing the backend.

## How X-Fetch Works

**X-Fetch** (Probabilistic Early Recomputation) is an algorithm that randomly refreshes a cache item *before* it actually expires.

Instead of waiting for the hard TTL (Time-To-Live), the system calculates a probabilistic value based on:
1. The time remaining until expiration.
2. The time it took to generate the value previously (`generation_time`).
3. A configured beta coefficient (`x_fetch_beta`).

If the check passes, one lucky request triggers the refresh early, while others continue to use the current value. This spreads the recomputation load and prevents the "cliff edge" of expiration.

## Configuration

You can enable X-Fetch by setting the `x_fetch_beta` option in `CacheOptions`.

```php
use Fyennyi\AsyncCache\CacheOptions;

$options = new CacheOptions(
    ttl: 600,
    x_fetch_beta: 1.0 // Standard value. Set to 0 to disable.
);
```

### Tuning Beta

- **`1.0`**: Standard setting. Effectively mimics the optimal recomputation point.
- **Higher values (> 1.0)**: Aggressive early recomputation. Good for very expensive queries.
- **Lower values (< 1.0)**: Lazy recomputation. Closer to standard expiration.
- **`0`**: Disables X-Fetch logic.

## Why Use It?

- **Zero Downtime**: The cache is refreshed in the background before it disappears.
- **No Locking Required**: Unlike traditional stampede protection (which uses locks), X-Fetch is non-blocking and statistically efficient.
