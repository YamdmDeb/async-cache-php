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

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 adapter that chains multiple cache layers
 */
class ChainCacheAdapter implements CacheInterface
{
    /**
     * @param CacheInterface[] $adapters Ordered list of adapters (L1, L2, L3...)
     */
    public function __construct(
        private array $adapters
    ) {
    }

    /**
     * Retrieves an item from the first adapter that has it
     *
     * @param  string  $key      Cache key
     * @param  mixed   $default  Default value if not found in any layer
     * @return mixed             Found value or default
     */
    public function get(string $key, mixed $default = null) : mixed
    {
        foreach ($this->adapters as $index => $adapter) {
            $value = $adapter->get($key);
            if ($value !== null) {
                // Backfill: populate faster layers above this one
                for ($i = 0; $i < $index; $i++) {
                    $this->adapters[$i]->set($key, $value);
                }
                return $value;
            }
        }

        return $default;
    }

    /**
     * Stores an item across all cache layers
     *
     * @param  string                  $key    Cache key
     * @param  mixed                   $value  Value to store
     * @param  null|int|\DateInterval  $ttl    Optional TTL
     * @return bool                            True if all adapters succeeded
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null) : bool
    {
        $success = true;
        foreach ($this->adapters as $adapter) {
            $success = $adapter->set($key, $value, $ttl) && $success;
        }
        return $success;
    }

    /**
     * Removes an item from all cache layers
     *
     * @param  string  $key  Cache key
     * @return bool          True if all adapters succeeded
     */
    public function delete(string $key) : bool
    {
        $success = true;
        foreach ($this->adapters as $adapter) {
            $success = $adapter->delete($key) && $success;
        }
        return $success;
    }

    /**
     * Clears all cache layers
     *
     * @return bool True if all adapters succeeded
     */
    public function clear() : bool
    {
        $success = true;
        foreach ($this->adapters as $adapter) {
            $success = $adapter->clear() && $success;
        }
        return $success;
    }

    /**
     * Checks if an item exists in any cache layer
     *
     * @param  string  $key  Cache key
     * @return bool
     */
    public function has(string $key) : bool
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrieves multiple items from the chain
     *
     * @param  iterable  $keys     List of keys
     * @param  mixed     $default  Default value
     * @return iterable            Map of key => value
     */
    public function getMultiple(iterable $keys, mixed $default = null) : iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * Stores multiple items across all cache layers
     *
     * @param  iterable                $values  Map of key => value
     * @param  null|int|\DateInterval  $ttl     Optional TTL
     * @return bool                             True if all succeeded
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null) : bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            $success = $this->set($key, $value, $ttl) && $success;
        }
        return $success;
    }

    /**
     * Removes multiple items from all cache layers
     *
     * @param  iterable  $keys  List of keys
     * @return bool             True if all succeeded
     */
    public function deleteMultiple(iterable $keys) : bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }
        return $success;
    }
}
