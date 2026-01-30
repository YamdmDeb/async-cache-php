# Getting Started

This guide will help you set up **Async Cache PHP** and start caching your asynchronous operations.

## Prerequisites

Ensure you have installed the package via Composer:

```bash
composer require fyennyi/async-cache-php
```

## Basic Setup

To start using the library, you need to create an instance of `AsyncCacheManager`. The recommended way is to use the fluent `configure()` method.

### 1. Initialize a Cache Adapter

The library supports any PSR-16 compliant cache adapter or ReactPHP Cache implementation.

=== "ReactPHP ArrayCache"

    Suitable for testing or single-process applications.

    ```php
    use React\Cache\ArrayCache;
    use Fyennyi\AsyncCache\Storage\ReactCacheAdapter;

    $cache = new ArrayCache();
    $adapter = new ReactCacheAdapter($cache);
    ```

=== "Symfony Filesystem"

    Suitable for persistent caching. Requires `symfony/cache` package.

    ```php
    use Symfony\Component\Cache\Adapter\FilesystemAdapter;
    use Symfony\Component\Cache\Psr16Cache;

    $psr16Cache = new Psr16Cache(new FilesystemAdapter());
    // The library will automatically adapt PSR-16 instances
    ```

### 2. Create the Manager

Use the fluent configuration API to construct the manager.

```php
use Fyennyi\AsyncCache\AsyncCacheManager;

$manager = new AsyncCacheManager(
    AsyncCacheManager::configure($cache)
        ->build()
);
```

## Wrapping Operations

The core functionality is provided by the `wrap` method. It takes a cache key, a factory function (which returns a value or a promise), and configuration options.

```php
use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Enum\CacheStrategy;

$options = new CacheOptions(
    ttl: 60,                        // Data is fresh for 60 seconds
    strategy: CacheStrategy::Strict // Default strategy
);

$promise = $manager->wrap(
    'my_cache_key',
    function () {
        // Your async operation here
        // Should return a value or a Promise
        return perform_heavy_calculation();
    },
    $options
);

// Handle the result asynchronously
$promise->then(function ($result) {
    echo $result;
});

// Or wait synchronously with React\Async\await()
$result = \React\Async\await($promise);
```

## Next Steps

- Explore [Caching Strategies](strategies.md) to optimize performance.
- Learn about [Atomic Operations](atomic.md) for counters.
- Configure [Rate Limiting](rate-limiting.md) for external APIs.
