# Async Cache PHP

[![Latest Stable Version](https://img.shields.io/packagist/v/fyennyi/async-cache-php.svg?label=Packagist&logo=packagist)](https://packagist.org/packages/fyennyi/async-cache-php)
[![Total Downloads](https://img.shields.io/packagist/dt/fyennyi/async-cache-php.svg?label=Downloads&logo=packagist)](https://packagist.org/packages/fyennyi/async-cache-php)
[![License](https://img.shields.io/packagist/l/fyennyi/async-cache-php.svg?label=Licence&logo=open-source-initiative)](https://packagist.org/packages/fyennyi/async-cache-php)
[![Tests](https://img.shields.io/github/actions/workflow/status/Fyennyi/async-cache-php/phpunit.yml?label=Tests&logo=github)](https://github.com/Fyennyi/async-cache-php/actions/workflows/phpunit.yml)
[![Test Coverage](https://img.shields.io/codecov/c/github/Fyennyi/async-cache-php?label=Test%20Coverage&logo=codecov)](https://app.codecov.io/gh/Fyennyi/async-cache-php)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/Fyennyi/async-cache-php/phpstan.yml?label=PHPStan&logo=github)](https://github.com/Fyennyi/async-cache-php/actions/workflows/phpstan.yml)

An asynchronous caching abstraction layer for PHP with built-in rate limiting and stale-while-revalidate support. This library is designed to wrap promise-based operations (like ReactPHP Promises) to provide robust caching strategies suitable for high-load or rate-limited API clients.

## Features

- **Asynchronous Caching**: Wraps `PromiseInterface` or any callable returning a value/promise to handle caching transparently without blocking execution.
- **Stale-While-Revalidate**: Supports background revalidation and stale-on-error patterns.
- **X-Fetch (Probabilistic Early Recomputation)**: Implements the X-Fetch algorithm to prevent cache stampedes (dog-pile effect).
- **Atomic Operations**: Support for atomic `increment` and `decrement` operations using Symfony Lock.
- **Logical vs. Physical TTL**: Separates the "freshness" of data from its "existence" in the cache, enabling soft expiration patterns.
- **Rate Limiting Integration**: Supports Symfony Rate Limiter for request throttling.
- **PSR-16 & ReactPHP Compatible**: Works with any PSR-16 Simple Cache adapter or ReactPHP Cache implementation.

## Installation

To install the Async Cache PHP library, run the following command in your terminal:

```bash
composer require fyennyi/async-cache-php
```

## Usage

### Basic Setup

The easiest way to create a manager is using the fluent configuration API.

```php
use Fyennyi\AsyncCache\AsyncCacheManager;
use React\Cache\ArrayCache;

// 1. Setup Cache (using ReactPHP ArrayCache as an example)
$cache = new ArrayCache();

// 2. Create the Manager using fluent configuration
$manager = new AsyncCacheManager(
    AsyncCacheManager::configure($cache)
        ->build()
);
```

### Wrapping an Async Operation

Use the `wrap` method to cache a promise-based operation.

```php
use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Enum\CacheStrategy;
use React\Http\Browser;

$browser = new \React\Http\Browser();

$options = new CacheOptions(
    ttl: 60,                        // Data is fresh for 60 seconds
    strategy: CacheStrategy::Strict // Default strategy
);

$promise = $manager->wrap(
    'cache_key_user_1',
    fn() => $browser->get('https://api.example.com/users/1')->then(
        fn($response) => (string)$response->getBody()
    ),
    $options
);

// Handle the result asynchronously
$promise->then(function ($data) {
    echo "User data: " . $data;
});
```

### Advanced Configuration Options

The `CacheOptions` DTO allows you to configure behavior per request:

```php
use Fyennyi\AsyncCache\Enum\CacheStrategy;

new CacheOptions(
    ttl: 300,                        // Time in seconds data is considered fresh
    stale_grace_period: 86400,       // Keep stale data physically in cache for 24h
    strategy: CacheStrategy::Strict, // Strict, Background, or ForceRefresh
    rate_limit_key: 'nominatim',     // Key for rate limiting (if limiter is configured)
    serve_stale_if_limited: true,    // Return stale data if rate limited
    tags: ['geo', 'kyiv'],           // Cache tags (if adapter supports them)
    compression: false,              // Enable data compression
    compression_threshold: 1024,     // Minimum size in bytes to trigger compression
    fail_safe: true,                 // Catch cache exceptions and treat as misses
    x_fetch_beta: 1.0                // Beta coefficient for X-Fetch (0 to disable)
);
```

### Atomic Increments

```php
$manager->increment('page_views', 1)->then(function($newValue) {
    echo "New value: " . $newValue;
});
```

## How It Works

1. **Cache Hit**: If data is found in the cache and is fresh (within `ttl`), the promise resolves immediately with the cached value. The factory function is not called.
2. **Cache Miss**: If data is not found, the factory function is executed, and the result is stored in the cache.
3. **Stale Data**:
   - If data is in the cache but expired (older than `ttl`), the manager behavior depends on the chosen `strategy`.
   - **Strict**: Fetches fresh data while the request waits.
   - **Background**: Returns stale data immediately and triggers an asynchronous refresh in the background.
4. **X-Fetch**: Helps avoid simultaneous cache misses for the same key by probabilistic early recomputation.

## Contributing

Contributions are welcome! Please follow these steps:

1. Fork the project.
2. Create your feature branch (`git checkout -b feature/AmazingFeature`).
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`).
4. Push to the branch (`git push origin feature/AmazingFeature`).
5. Open a Pull Request.

## License

This library is licensed under the CSSM Unlimited License v2.0 (CSSM-ULv2). See the [LICENSE](LICENSE) file for details.
