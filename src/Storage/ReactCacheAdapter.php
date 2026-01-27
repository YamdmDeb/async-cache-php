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

use Fyennyi\AsyncCache\Bridge\PromiseAdapter;
use Fyennyi\AsyncCache\Core\Future;
use React\Cache\CacheInterface as ReactCacheInterface;

/**
 * Adapter for reactphp/cache to be used within AsyncCache
 */
class ReactCacheAdapter implements AsyncCacheAdapterInterface
{
    /**
     * @param  ReactCacheInterface  $react_cache  The ReactPHP cache implementation
     */
    public function __construct(private ReactCacheInterface $react_cache) {}

    /**
     * @inheritDoc
     */
    public function get(string $key) : Future
    {
        return PromiseAdapter::toFuture($this->react_cache->get($key));
    }

    /**
     * @inheritDoc
     *
     * @param  iterable<string>  $keys
     */
    public function getMultiple(iterable $keys) : Future
    {
        // ReactPHP cache doesn't have getMultiple, we simulate it
        $promises = [];
        foreach ($keys as $key) {
            $promises[$key] = $this->react_cache->get($key);
        }

        return PromiseAdapter::toFuture(\React\Promise\all($promises));
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, ?int $ttl = null) : Future
    {
        return PromiseAdapter::toFuture($this->react_cache->set($key, $value, $ttl));
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key) : Future
    {
        return PromiseAdapter::toFuture($this->react_cache->delete($key));
    }

    /**
     * @inheritDoc
     */
    public function clear() : Future
    {
        return PromiseAdapter::toFuture($this->react_cache->clear());
    }
}
