# Events & Telemetry

Async Cache PHP dispatches PSR-14 events to help you monitor and debug cache behavior.

## Available Events

All events reside in the `Fyennyi\AsyncCache\Event` namespace.

| Event Class | Description |
| :--- | :--- |
| `CacheHitEvent` | Dispatched when a valid item is found in the cache. Contains the value. |
| `CacheMissEvent` | Dispatched when the item is missing and the factory is about to be called. |
| `CacheStatusEvent` | Telemetry event containing timing info, status, and tags. |
| `RateLimitExceededEvent` | Dispatched when the rate limiter blocks a request. |

## Cache Status Values

The `CacheStatusEvent` contains a `status` property with one of the following values from `Fyennyi\AsyncCache\Enum\CacheStatus`:

| Status | Description |
| :--- | :--- |
| `Hit` | Data was found in cache and is fresh. |
| `Miss` | Data was not found, factory will be called. |
| `Stale` | Data was found but expired, stale data returned (Background strategy). |
| `XFetch` | X-Fetch algorithm triggered early recomputation. |
| `RateLimited` | Request was blocked by rate limiter. |
| `Bypass` | Cache was bypassed (ForceRefresh strategy). |

## Setting Up an Event Dispatcher

You can pass any PSR-14 compliant Event Dispatcher (like Symfony's) to the builder.

```php
use Symfony\Component\EventDispatcher\EventDispatcher;
use Fyennyi\AsyncCache\AsyncCacheManager;
use Fyennyi\AsyncCache\Event\CacheHitEvent;

$dispatcher = new EventDispatcher();

// Add a listener
$dispatcher->addListener(CacheHitEvent::class, function ($event) {
    echo "Cache Hit for key: " . $event->key . "\n";
});

$manager = new AsyncCacheManager(
    AsyncCacheManager::configure($cache)
        ->withEventDispatcher($dispatcher)
        ->build()
);
```

## Telemetry Logging

The `CacheStatusEvent` is particularly useful for logging metrics (e.g., to Prometheus or DataDog). It provides:

- **`key`**: The cache key identifier.
- **`status`**: Why the result was returned (Hit, Miss, Stale, XFetch, Bypass).
- **`latency`**: How long the lookup took in seconds.
- **`tags`**: Categories associated with the key.
- **`timestamp`**: When the event occurred.
