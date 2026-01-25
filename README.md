# Async Cache PHP

[![Latest Stable Version](https://img.shields.io/packagist/v/fyennyi/async-cache-php.svg?label=Packagist&logo=packagist)](https://packagist.org/packages/fyennyi/async-cache-php)
[![Total Downloads](https://img.shields.io/packagist/dt/fyennyi/async-cache-php.svg?label=Downloads&logo=packagist)](https://packagist.org/packages/fyennyi/async-cache-php)
[![License](https://img.shields.io/packagist/l/fyennyi/async-cache-php.svg?label=Licence&logo=open-source-initiative)](https://packagist.org/packages/fyennyi/async-cache-php)
[![Tests](https://img.shields.io/github/actions/workflow/status/Fyennyi/async-cache-php/phpunit.yml?label=Tests&logo=github)](https://github.com/Fyennyi/async-cache-php/actions/workflows/phpunit.yml)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/Fyennyi/async-cache-php/phpstan.yml?label=PHPStan&logo=github)](https://github.com/Fyennyi/async-cache-php/actions/workflows/phpstan.yml)
[![Test Coverage](https://img.shields.io/codecov/c/github/Fyennyi/async-cache-php?label=Test%20Coverage&logo=codecov)](https://app.codecov.io/gh/Fyennyi/async-cache-php)

An asynchronous caching abstraction layer for PHP with built-in rate limiting and stale-while-revalidate support. This library is designed to wrap promise-based operations (like Guzzle Promises) to provide robust caching strategies suitable for high-load or rate-limited API clients.

## Features

- **Asynchronous Caching**: Wraps `PromiseInterface` to handle caching transparently without blocking execution.
- **Stale-While-Limited Strategy**: If the rate limit is hit, the library can return stale data (if available) instead of failing, improving resilience.
- **Logical vs. Physical TTL**: Separates the "freshness" of data from its "existence" in the cache, enabling soft expiration patterns.
- **Rate Limiting Interface**: Includes support for multiple rate limiter implementations (InMemory, Symfony Rate Limiter) with automatic detection and factory pattern.
- **PSR-16 Compliant**: Works with any PSR-16 Simple Cache adapter.

## Installation

To install the Async Cache PHP library, run the following command in your terminal:

```bash
 composer require fyennyi/async-cache-php
```

## Usage

### Basic Setup

You need a PSR-16 cache implementation and a rate limiter.

```php
use Fyennyi\AsyncCache\AsyncCacheManager;
use Fyennyi\AsyncCache\RateLimiter\InMemoryRateLimiter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

// 1. Setup Cache (using Symfony Cache as an example)
$psr16Cache = new Psr16Cache(new FilesystemAdapter());

// 2. Setup Rate Limiter
$rateLimiter = new InMemoryRateLimiter();
// Allow 1 request every 5 seconds for the 'my_api' key
$rateLimiter->configure('my_api', 5);

// 3. Create the Manager
$manager = new AsyncCacheManager($psr16Cache, $rateLimiter);
```

### Wrapping an Async Operation

Use the `wrap` method to cache a promise-based operation.

```php
use Fyennyi\AsyncCache\CacheOptions;
use GuzzleHttp\Client;

$client = new Client();

$options = new CacheOptions(
    ttl: 60,                        // Data is fresh for 60 seconds
    rate_limit_key: 'my_api',       // Use the 'my_api' rate limit bucket
    serve_stale_if_limited: true    // If rate limited, return old data instead of failing
);

$promise = $manager->wrap(
    'cache_key_user_1',
    fn() => $client->getAsync('https://api.example.com/users/1'),
    $options
);

$response = $promise->wait();
```

### How It Works

1. **Cache Hit**: If data is found in the cache and is fresh (within `ttl`), the promise resolves immediately with the cached value. The factory function is not called.
2. **Cache Miss**: If data is not found, the factory function is executed, and the result is stored in the cache.
3. **Stale Data & Rate Limits**:
   - If data is in the cache but expired (older than `ttl`), the manager checks the Rate Limiter.
   - If the request is **Rate Limited** (too frequent) AND `serve_stale_if_limited` is `true`, the manager returns the **stale data** immediately. This prevents API errors and keeps your application responsive.
   - If the request is allowed by the Rate Limiter, the factory function is executed to fetch fresh data.

## Configuration Options

The `CacheOptions` DTO allows you to configure behavior per request:

```php
new CacheOptions(
    ttl: 300,                     // Time in seconds data is considered fresh
    rate_limit_key: 'nominatim',  // Key for rate limiting (null to disable)
    serve_stale_if_limited: true, // Return stale data if rate limited
    stale_grace_period: 86400,    // Keep stale data physically in cache for 24h
    force_refresh: false,         // Ignore cache and force new request
    tags: ['geo', 'kyiv']         // Cache tags (if adapter supports them)
);
```

## Contributing

Contributions are welcome! Please follow these steps:

1. Fork the project.
2. Create your feature branch (`git checkout -b feature/AmazingFeature`).
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`).
4. Push to the branch (`git push origin feature/AmazingFeature`).
5. Open a Pull Request.

## License

This library is licensed under the CSSM Unlimited License v2.0 (CSSM-ULv2). See the [LICENSE](LICENSE) file for details.
