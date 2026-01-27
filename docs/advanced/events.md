# Events & Telemetry

Async Cache PHP dispatches PSR-14 events to help you monitor and debug cache behavior.

## Available Events

All events reside in the `Fyennyi\AsyncCache\Event` namespace.

| Event Class |
| :--- |
| `CacheHitEvent` | Dispatched when a valid item is found in the cache. Contains the value. |
| `CacheMissEvent` | Dispatched when the item is missing and the factory is about to be called. |
| `CacheStatusEvent` | Telemetry event containing timing info, status (`Hit`, `Miss`, `Stale`, `XFetch`), and tags. |
| `RateLimitExceededEvent` | Dispatched when the rate limiter blocks a request. |

## Setting Up an Event Dispatcher

You can pass any PSR-14 compliant Event Dispatcher (like Symfony's) to the builder.

```php
use Symfony\Component\EventDispatcher\EventDispatcher;
use Fyennyi\AsyncCache\AsyncCacheBuilder;

$dispatcher = new EventDispatcher();

// Add a listener
$dispatcher->addListener(CacheHitEvent::class, function ($event) {
    echo "Cache Hit for key: " . $event->key . "\n";
});

$manager = AsyncCacheBuilder::create($adapter)
    ->withEventDispatcher($dispatcher)
    ->build();
```

## Telemetry Logging

The `CacheStatusEvent` is particularly useful for logging metrics (e.g., to Prometheus or DataDog). It provides:
- **Duration**: How long the lookup took.
- **Status**: Why the result was returned (Hit, Miss, Stale fallback, etc.).
- **Tags**: Categories associated with the key.
