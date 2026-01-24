# Wrapping Operations

The core of the library is the `wrap` method. It allows you to wrap any asynchronous operation (a factory that returns a `PromiseInterface`) with caching logic.

## The `wrap` Method

```php
public function wrap(
    string $key, 
    callable $factory, 
    CacheOptions $options
): PromiseInterface
```

### Example: Wrapping a Guzzle Request

```php
<?php

use Fyennyi\AsyncCache\CacheOptions;
use GuzzleHttp\Client;

$client = new Client();

// Configure how this specific request should be cached
$options = new CacheOptions(
    ttl: 60,                        // Data is fresh for 60 seconds
    rate_limit_key: 'my_api',       // Match the limiter config
    serve_stale_if_limited: true    // Use stale data on rate limit
);

$promise = $manager->wrap(
    'user_profile_1',
    fn() => $client->getAsync('https://api.example.com/users/1'),
    $options
);

// The factory is only called if:
// 1. Data is not in cache
// 2. Data is stale AND the rate limiter allows a new request
$response = $promise->wait();
```

## Cache Options

The `CacheOptions` DTO provides granular control over the caching behavior:

- **`ttl`**: Time in seconds the data is considered fresh.
- **`rate_limit_key`**: Identifier for rate limiting.
- **`serve_stale_if_limited`**: Whether to return expired data when rate limited.
- **`stale_grace_period`**: How long to keep expired data in the cache (physical TTL).
- **`force_refresh`**: Bypass cache and always call the factory.
- **`tags`**: Tags for cache invalidation.
