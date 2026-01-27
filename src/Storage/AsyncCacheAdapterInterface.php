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

use Fyennyi\AsyncCache\Core\Future;

/**
 * Interface for truly asynchronous cache adapters
 */
interface AsyncCacheAdapterInterface
{
    /**
     * Retrieves an item from the cache
     *
     * @param  string  $key  The unique key of this item in the cache
     * @return Future        Resolves to the cached value or null on miss
     */
    public function get(string $key) : Future;

    /**
     * Obtains multiple cache items by their unique keys
     *
     * @param  iterable  $keys  A list of keys that can be obtained in a single operation
     * @return Future           Resolves to an array of key => value pairs
     */
    public function getMultiple(iterable $keys) : Future;

    /**
     * Persists data in the cache, uniquely referenced by a key
     *
     * @param  string    $key    The key of the item to store
     * @param  mixed     $value  The value of the item to store
     * @param  int|null  $ttl    Optional. The TTL value of this item
     * @return Future            Resolves to true on success and false on failure
     */
    public function set(string $key, mixed $value, ?int $ttl = null) : Future;

    /**
     * Deletes an item from the cache by its unique key
     *
     * @param  string  $key  The unique cache key of the item to delete
     * @return Future        Resolves to true on success and false on failure
     */
    public function delete(string $key) : Future;

    /**
     * Wipes clean the entire cache's keys
     *
     * @return Future  Resolves to true on success and false on failure
     */
    public function clear() : Future;
}
