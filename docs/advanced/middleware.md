# Middleware Pipeline

The logic of Async Cache PHP is built on a **Middleware Pipeline**. Every request passed to `wrap()` travels through a series of middleware components, each responsible for a specific aspect of the caching lifecycle.

## Default Pipeline

When you create a manager using `AsyncCacheManager::configure()`, the following middleware stack is assembled (in order):

1. **`CoalesceMiddleware`**: Prevents duplicate requests for the same key happening at the same time. If 10 requests come in for `key_A`, only one factory is executed, and the result is shared.
2. **`StaleOnErrorMiddleware`**: If the factory fails (throws an exception), this middleware tries to return stale data from the cache instead of propagating the error.
3. **`CacheLookupMiddleware`**: Checks if the item is in the cache. Handles `CacheStrategy` logic (Strict vs Background) and X-Fetch calculations.
4. **`TagValidationMiddleware`**: Validates cache tags and handles tag-based invalidation.
5. **`AsyncLockMiddleware`**: Acquires a lock before calling the factory to ensure only one process generates the data (Cache Stampede protection via locking).
6. **`SourceFetchMiddleware`**: The final handler. It executes the user's factory function and saves the result to the storage.

## Additional Middleware

The library also provides optional middleware that can be added to the pipeline:

- **`CircuitBreakerMiddleware`**: Implements the circuit breaker pattern to prevent cascading failures when external services are down.
- **`RetryMiddleware`**: Automatically retries failed operations with configurable backoff strategies.

## Custom Middleware

You can inject your own middleware into the pipeline using the configuration builder.

### 1. Implement `MiddlewareInterface`

```php
use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;
use Fyennyi\AsyncCache\Core\CacheContext;
use React\Promise\PromiseInterface;

class MyCustomMiddleware implements MiddlewareInterface
{
    public function handle(CacheContext $context, callable $next): PromiseInterface
    {
        // Pre-processing
        echo "Processing key: " . $context->key . "\n";

        // Call next middleware
        return $next($context)->then(function ($value) {
            // Post-processing
            echo "Finished!\n";
            return $value;
        });
    }
}
```

### 2. Register with Builder

```php
$manager = new AsyncCacheManager(
    AsyncCacheManager::configure($cache)
        ->withMiddleware(new MyCustomMiddleware())
        ->build()
);
```

!!! warning
    Custom middleware is prepended to the stack (runs before default middleware). If you need precise control over the order, you might need to construct the `AsyncCacheManager` manually, passing the full array of middleware.
