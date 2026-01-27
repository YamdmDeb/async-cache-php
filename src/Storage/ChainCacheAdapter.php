<?php

/*
 *
 *     _                          ____           _            ____  _   _ ____
 *    / \   ___ _   _ _ __   ___ / ___|__ _  ___| |__   ___  |  _ \| | | |  _ \
 *   / _ \ / __| | | | '_ \ / __| |   / _` |/ __| '_ \ / _ \ | |_) | |_| | |_) |
 *  / ___ \\__ \ |_| | | | | (__| |__| (_| | (__| | | |  __/ |  __/|  _  |  __/
 * /_/   \_\___/\__, |_| |_|\___|\____\__,_|\___|_| |_|\___| |_|   |_| |_|_|
 *              |___/
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author Serhii Cherneha
 * @link https://chernega.eu.org/
 *
 *
 */

namespace Fyennyi\AsyncCache\Storage;

use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
use React\Cache\CacheInterface as ReactCacheInterface;

/**
 * Asynchronous adapter that chains multiple cache layers (L1, L2, L3...)
 */
class ChainCacheAdapter implements AsyncCacheAdapterInterface
{
    /** @var AsyncCacheAdapterInterface[] Ordered list of asynchronous adapters */
    private array $adapters = [];

     /**
      * @param  AsyncCacheAdapterInterface[]  $adapters  Ordered list of adapters (Psr, React or Async)
      */
    public function __construct(array $adapters)
    {
        foreach ($adapters as $adapter) {
            if ($adapter instanceof AsyncCacheAdapterInterface) {
                $this->adapters[] = $adapter;
            } elseif ($adapter instanceof PsrCacheInterface) {
                $this->adapters[] = new PsrToAsyncAdapter($adapter);
            } elseif ($adapter instanceof ReactCacheInterface) {
                $this->adapters[] = new ReactCacheAdapter($adapter);
            }
        }
    }

    /**
     * Retrieves an item from the first layer that has it, then backfills upper layers
     *
     * @param  string  $key  The unique key of this item in the cache
     * @return Future        Resolves to the cached value or null on miss
     */
    public function get(string $key) : Future
    {
        $deferred = new Deferred();
        $this->resolveLayer($key, 0, $deferred);
        return $deferred->future();
    }

    /**
     * Recursive resolution of cache layers with asynchronous backfilling
     *
     * @param  string    $key       Cache key to find
     * @param  int       $index     Current layer index in the adapters array
     * @param  Deferred  $deferred  The original deferred to resolve when found
     * @return void
     */
    private function resolveLayer(string $key, int $index, Deferred $deferred) : void
    {
        if (! isset($this->adapters[$index])) {
            $deferred->resolve(null);
            return;
        }

        $this->adapters[$index]->get($key)->onResolve(
            function ($value) use ($key, $index, $deferred) {
                if ($value !== null) {
                    // Backfill: populate all faster layers above this one asynchronously
                for ($i = 0; $i < $index && isset($this->adapters[$i]); $i++) {
                        $this->adapters[$i]->set($key, $value);
                    }
                    $deferred->resolve($value);
                    return;
                }

                // Try next layer in the hierarchy
                $this->resolveLayer($key, $index + 1, $deferred);
            },
            function () use ($key, $index, $deferred) {
                // If a layer fails critically, we still attempt the next one for resilience
                $this->resolveLayer($key, $index + 1, $deferred);
            }
        );
    }

    /**
     * Obtains multiple cache items by their unique keys
     *
     * @param  iterable<string>  $keys  A list of keys that can be obtained in a single operation
     * @return Future            Resolves to an array of key => value pairs
     */
    public function getMultiple(iterable $keys) : Future
    {
        $deferred = new Deferred();
        $results = [];
        /** @var array<string> $keys_array */
        $keys_array = is_array($keys) ? $keys : iterator_to_array($keys);
        $total_keys = count($keys_array);

        if ($total_keys === 0) {
            $deferred->resolve([]);
            return $deferred->future();
        }

        $processed_count = 0;
            foreach ($keys_array as $key) {
                $this->get($key)->onResolve(function ($value) use ($key, &$results, &$processed_count, $total_keys, $deferred) {
                    $results[$key] = $value;
                    $processed_count++;
                    if ($processed_count === $total_keys) {
                        $deferred->resolve($results);
                    }
                });
            }

        return $deferred->future();
    }

    /**
     * Persists data in all cache layers concurrently
     *
     * @param  string    $key    The key of the item to store
     * @param  mixed     $value  The value of the item to store
     * @param  int|null  $ttl    Optional. The TTL value of this item
     * @return Future            Resolves to true on success and false on failure
     */
    public function set(string $key, mixed $value, ?int $ttl = null) : Future
    {
        $deferred = new Deferred();
        $total_adapters = count($this->adapters);

        if ($total_adapters === 0) {
            $deferred->resolve(true);
            return $deferred->future();
        }

        $resolved_count = 0;
        $all_success = true;

        foreach ($this->adapters as $adapter) {
            $adapter->set($key, $value, $ttl)->onResolve(function ($res) use (&$resolved_count, $total_adapters, $deferred, &$all_success) {
                $resolved_count++;
                $all_success = $res && $all_success;
                if ($resolved_count === $total_adapters) {
                    $deferred->resolve($all_success);
                }
            });
        }

        return $deferred->future();
    }

    /**
     * Deletes an item from all cache layers concurrently
     *
     * @param  string  $key  The unique cache key of the item to delete
     * @return Future        Resolves to true on success and false on failure
     */
    public function delete(string $key) : Future
    {
        $deferred = new Deferred();
        $total_adapters = count($this->adapters);

        if ($total_adapters === 0) {
            $deferred->resolve(true);
            return $deferred->future();
        }

        $resolved_count = 0;
        foreach ($this->adapters as $adapter) {
            $adapter->delete($key)->onResolve(function () use (&$resolved_count, $total_adapters, $deferred) {
                $resolved_count++;
                if ($resolved_count === $total_adapters) {
                    $deferred->resolve(true);
                }
            });
        }

        return $deferred->future();
    }

    /**
     * Wipes clean all cache layers concurrently
     *
     * @return Future Resolves to true on success and false on failure
     */
    public function clear() : Future
    {
        $deferred = new Deferred();
        $total_adapters = count($this->adapters);

        if ($total_adapters === 0) {
            $deferred->resolve(true);
            return $deferred->future();
        }

        $resolved_count = 0;
        foreach ($this->adapters as $adapter) {
            $adapter->clear()->onResolve(function () use (&$resolved_count, $total_adapters, $deferred) {
                $resolved_count++;
                if ($resolved_count === $total_adapters) {
                    $deferred->resolve(true);
                }
            });
        }

        return $deferred->future();
    }
}
