# Atomic Operations

Async Cache PHP provides atomic `increment` and `decrement` operations, which are essential for implementing counters, quotas, and rate limits.

## Why Atomic?

In an asynchronous environment, multiple processes or coroutines might try to update the same counter simultaneously. Without atomicity, you get **race conditions**:

1. Process A reads value `10`.
2. Process B reads value `10`.
3. Process A writes `11`.
4. Process B writes `11`.
Result: `11` (should be `12`).

Async Cache PHP solves this by using **Locks** (via Symfony Lock component) to ensure sequential updates.

## Usage

### Increment

Increases a value by a given step (default 1). If the key doesn't exist, it starts from 0.

```php
// Increment 'page_views' by 1
$newValue = $manager->increment('page_views')->wait();

// Increment by 5
$newValue = $manager->increment('page_views', 5)->wait();
```

### Decrement

Decreases a value by a given step.

```php
// Decrement stock count
$newValue = $manager->decrement('product_stock')->wait();
```

## Configuration

Atomic operations also support `CacheOptions`, although mainly for TTL purposes.

```php
$options = new CacheOptions(ttl: 3600);
$manager->increment('daily_stats', 1, $options);
```

!!! note
    These operations are fully asynchronous and return a `Future`. Always `wait()` or attach listeners if you need the result immediately.
