# Stale-While-Limited Strategy

One of the most powerful features of this library is the **Stale-While-Limited** strategy. It ensures that your application stays responsive even when external APIs are hitting their limits.

## The Problem

Traditional caches have two states:
1. **Fresh**: Return the data.
2. **Expired**: Delete data and block until new data arrives.

If the external API returns a "429 Too Many Requests" error during the "Expired" state, your application crashes or returns an error to the user.

## The Solution

Async Cache PHP introduces a middle state: **Stale but Resilient**.

1. Data is fresh for `ttl` seconds.
2. After `ttl`, the data is "stale" but **not deleted** (it remains in cache for `stale_grace_period`).
3. If a request comes in during the stale period:
    - We try to refresh from the API.
    - If the API is **Rate Limited** (determined by the limiter), we **return the stale data** instead of an error.

## Benefits

- **Improved Availability**: Users get slightly old data instead of an error page.
- **Lower Latency**: No waiting for slow API responses if you're already at the limit.
- **Self-Healing**: Once the rate limit resets, the library automatically fetches fresh data.
