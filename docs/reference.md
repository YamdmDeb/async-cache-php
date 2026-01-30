# API Reference

## `AsyncCacheManager`

The main class for caching operations. Create using fluent configuration.

### Static Methods

#### `configure(PsrCacheInterface|ReactCacheInterface|AsyncCacheAdapterInterface $cache_adapter): AsyncCacheConfigBuilder`
Creates a configuration builder for fluent setup.

```php
$manager = new AsyncCacheManager(
    AsyncCacheManager::configure($cache)
        ->withLogger($logger)
        ->withRateLimiter($limiter)
        ->withEventDispatcher($dispatcher)
        ->build()
);
```

### Instance Methods

#### `wrap(string $key, callable $promise_factory, CacheOptions $options): PromiseInterface`
Wraps an operation with caching logic. Returns a ReactPHP Promise.

#### `increment(string $key, int $step = 1, ?CacheOptions $options = null): PromiseInterface`
Atomically increments a cached value.

#### `decrement(string $key, int $step = 1, ?CacheOptions $options = null): PromiseInterface`
Atomically decrements a cached value.

#### `invalidateTags(array $tags): PromiseInterface`
Invalidates items by tags.

#### `clear(): PromiseInterface`
Clears the entire cache storage.

#### `delete(string $key): PromiseInterface`
Deletes a specific item from the cache.

## `AsyncCacheConfigBuilder`

Fluent builder for configuring the manager.

### `withRateLimiter(LimiterInterface $rate_limiter): self`
Configures a Symfony Rate Limiter.

### `withLogger(LoggerInterface $logger): self`
Sets a PSR-3 logger.

### `withLockFactory(LockFactory $lock_factory): self`
Sets a custom Symfony Lock factory.

### `withMiddleware(MiddlewareInterface $middleware): self`
Adds custom middleware to the pipeline.

### `withEventDispatcher(EventDispatcherInterface $dispatcher): self`
Sets a PSR-14 event dispatcher.

### `withSerializer(SerializerInterface $serializer): self`
Sets a custom serializer.

### `withClock(ClockInterface $clock): self`
Sets a PSR-20 clock implementation.

### `build(): AsyncCacheConfig`
Finalizes and returns the configuration.

## `CacheOptions`

| Property | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `ttl` | `?int` | `3600` | Logical expiration in seconds. |
| `strategy` | `CacheStrategy` | `Strict` | Caching strategy (Strict, Background, ForceRefresh). |
| `stale_grace_period` | `int` | `86400` | Physical storage TTL in seconds. |
| `rate_limit_key` | `?string` | `null` | Key for rate limiting logic. |
| `serve_stale_if_limited`| `bool` | `true` | Return stale data on rate limit hits. |
| `tags` | `array` | `[]` | Tags for invalidation. |
| `x_fetch_beta` | `float` | `1.0` | X-Fetch algorithm coefficient. |
| `compression` | `bool` | `false` | Enable Zlib compression. |
| `compression_threshold` | `int` | `1024` | Minimum data size in bytes to trigger compression. |
| `fail_safe` | `bool` | `true` | Catch cache adapter exceptions and treat as misses. |

## `CacheOptionsBuilder`

Fluent builder for `CacheOptions`.

```php
$options = CacheOptionsBuilder::create()
    ->withTtl(300)
    ->withStrategy(CacheStrategy::Background)
    ->withStaleGracePeriod(3600)
    ->withCompression(true, 2048)
    ->withTags(['users', 'api'])
    ->build();
```
