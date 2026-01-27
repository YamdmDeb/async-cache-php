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

/**
 * Wraps a synchronous PSR-16 cache to act as an asynchronous adapter
 */
class PsrToAsyncAdapter implements AsyncCacheAdapterInterface
{
    /**
     * @param  PsrCacheInterface  $psr_cache  The synchronous PSR-16 cache
     */
    public function __construct(private PsrCacheInterface $psr_cache) {}

    /**
     * @inheritDoc
     */
    public function get(string $key) : Future
    {
        $deferred = new Deferred();
        try {
            $deferred->resolve($this->psr_cache->get($key));
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }
        return $deferred->future();
    }

    /**
     * @inheritDoc
     */
    public function getMultiple(iterable $keys) : Future
    {
        $deferred = new Deferred();
        try {
            $deferred->resolve($this->psr_cache->getMultiple($keys));
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }
        return $deferred->future();
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, ?int $ttl = null) : Future
    {
        $deferred = new Deferred();
        try {
            $deferred->resolve($this->psr_cache->set($key, $value, $ttl));
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }
        return $deferred->future();
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key) : Future
    {
        $deferred = new Deferred();
        try {
            $deferred->resolve($this->psr_cache->delete($key));
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }
        return $deferred->future();
    }

    /**
     * @inheritDoc
     */
    public function clear() : Future
    {
        $deferred = new Deferred();
        try {
            $deferred->resolve($this->psr_cache->clear());
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }
        return $deferred->future();
    }
}
