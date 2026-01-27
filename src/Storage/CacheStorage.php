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

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Serializer\PhpSerializer;
use Fyennyi\AsyncCache\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for asynchronous interactions with the cache adapter
 */
class CacheStorage
{
    private const TAG_PREFIX = 'tag_v:';
    private SerializerInterface $serializer;

    /**
     * @param  AsyncCacheAdapterInterface  $adapter     The asynchronous cache adapter
     * @param  LoggerInterface             $logger      Logger for reporting errors and debug info
     * @param  SerializerInterface|null    $serializer  Custom serializer implementation
     */
    public function __construct(
        private AsyncCacheAdapterInterface $adapter,
        private LoggerInterface $logger,
        ?SerializerInterface $serializer = null
    )
    {
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    /**
     * Retrieves an item from the cache and performs integrity checks asynchronously
     *
     * @param  string        $key      The cache key to retrieve
     * @param  CacheOptions  $options  Options for fail-safe and tag validation
     * @return Future                  Resolves to CachedItem|null
     */
    public function get(string $key, CacheOptions $options) : Future
    {
        $deferred = new Deferred();

        $this->adapter->get($key)->onResolve(
            function ($cached_item) use ($key, $options, $deferred) {
                if ($cached_item === null) {
                    $deferred->resolve(null);
                    return;
                }

                // Handle backward compatibility (old array format)
                if (is_array($cached_item) && array_key_exists('d', $cached_item) && array_key_exists('e', $cached_item)) {
                    $cached_item = new CachedItem($cached_item['d'], $cached_item['e']);
                }

                if (! $cached_item instanceof CachedItem) {
                    $deferred->resolve(null);
                    return;
                }

                // Tag Validation
                if (! empty($cached_item->tagVersions)) {
                    $this->getTagVersions(array_keys($cached_item->tagVersions))->onResolve(
                        function ($current_versions) use ($key, $cached_item, $deferred) {
                            foreach ($cached_item->tagVersions as $tag => $saved_version) {
                                if (($current_versions[$tag] ?? null) !== $saved_version) {
                                    $this->logger->debug('AsyncCache TAG_INVALID', ['key' => $key, 'tag' => $tag]);
                                    $deferred->resolve(null);
                                    return;
                                }
                            }
                            $deferred->resolve($this->processDecompression($cached_item, $key));
                        },
                        fn($e) => $deferred->reject($e)
                    );
                    return;
                }

                $deferred->resolve($this->processDecompression($cached_item, $key));
            },
            function ($e) use ($key, $options, $deferred) {
                if ($options->fail_safe) {
                    $this->logger->error('AsyncCache CACHE_GET_ERROR', ['key' => $key, 'error' => $e->getMessage()]);
                    $deferred->resolve(null);
                    return;
                }
                $deferred->reject($e);
            }
        );

        return $deferred->future();
    }

    /**
     * Stores an item in the cache asynchronously
     *
     * @param  string        $key              The cache key
     * @param  mixed         $data             The value to store
     * @param  CacheOptions  $options          Configuration for TTL and compression
     * @param  float         $generation_time  How long it took to generate the data
     * @return Future                          Resolves to bool
     */
    public function set(string $key, mixed $data, CacheOptions $options, float $generation_time = 0.0) : Future
    {
        $deferred = new Deferred();
        $logical_ttl = $options->ttl;
        $physical_ttl = $logical_ttl + $options->stale_grace_period;

        $on_tags_ready = function (array $tag_versions) use ($key, $data, $options, $generation_time, $physical_ttl, $deferred) {
            try {
                $is_compressed = false;
                if ($options->compression) {
                    $serialized_data = $this->serializer->serialize($data);
                    if (strlen($serialized_data) >= $options->compression_threshold) {
                        $compressed_data = @gzcompress($serialized_data);
                        if ($compressed_data !== false) {
                            $data = $compressed_data;
                            $is_compressed = true;
                        }
                    }
                }

                $item = new CachedItem(
                    data: $data,
                    logicalExpireTime: time() + $options->ttl,
                    isCompressed: $is_compressed,
                    generationTime: $generation_time,
                    tagVersions: $tag_versions
                );

                $this->adapter->set($key, $item, $physical_ttl)->onResolve(
                    fn($res) => $deferred->resolve($res),
                    fn($e) => $deferred->reject($e)
                );
            } catch (\Throwable $e) {
                $deferred->reject($e);
            }
        };

        if (! empty($options->tags)) {
            $this->getTagVersions($options->tags, true)->onResolve($on_tags_ready, fn($e) => $deferred->reject($e));
        } else {
            $on_tags_ready([]);
        }

        return $deferred->future();
    }

    /**
     * Invalidates specific tags asynchronously
     *
     * @param  array  $tags  List of tags to invalidate
     * @return Future        Resolves to true on success
     */
    public function invalidateTags(array $tags) : Future
    {
        $deferred = new Deferred();
        $count = count($tags);
        if ($count === 0) {
            $deferred->resolve(true);
            return $deferred->future();
        }

        $processed = 0;
        foreach ($tags as $tag) {
            $this->adapter->set(self::TAG_PREFIX . $tag, $this->generateVersion())->onResolve(function () use (&$processed, $count, $deferred) {
                $processed++;
                if ($processed === $count) {
                    $deferred->resolve(true);
                }
            });
        }

        $this->logger->info('AsyncCache TAGS_INVALIDATED', ['tags' => $tags]);
        return $deferred->future();
    }

    /**
     * Internal helper for decompression
     *
     * @param  CachedItem  $cached_item  The item to decompress if needed
     * @param  string      $key          The cache key for logging purposes
     * @return CachedItem|null           The decompressed item or null on error
     */
    private function processDecompression(CachedItem $cached_item, string $key) : ?CachedItem
    {
        if ($cached_item->isCompressed && is_string($cached_item->data)) {
            $decompressed_data = @gzuncompress($cached_item->data);
            if ($decompressed_data !== false) {
                $data = $this->serializer->unserialize($decompressed_data);
                return new CachedItem(
                    data: $data,
                    logicalExpireTime: $cached_item->logicalExpireTime,
                    version: $cached_item->version,
                    isCompressed: false,
                    generationTime: $cached_item->generationTime,
                    tagVersions: $cached_item->tagVersions
                );
            }
            $this->logger->error('AsyncCache DECOMPRESSION_ERROR', ['key' => $key]);
            return null;
        }
        return $cached_item;
    }

    /**
     * Fetches current versions for a set of tags asynchronously
     *
     * @param  array  $tags            List of tags to fetch
     * @param  bool   $create_missing  Whether to initialize missing tags with a new version
     * @return Future                  Resolves to an array of tag => version pairs
     */
    private function getTagVersions(array $tags, bool $create_missing = false) : Future
    {
        $deferred = new Deferred();
        $count = count($tags);
        if ($count === 0) {
            $deferred->resolve([]);
            return $deferred->future();
        }

        $keys = array_map(fn($t) => self::TAG_PREFIX . $t, $tags);
        $this->adapter->getMultiple($keys)->onResolve(function ($raw_versions) use ($tags, $create_missing, $deferred) {
            $versions = [];
            $set_futures = [];

            foreach ($tags as $tag) {
                $version = $raw_versions[self::TAG_PREFIX . $tag] ?? null;
                if ($version === null && $create_missing) {
                    $version = $this->generateVersion();
                    $set_futures[] = $this->adapter->set(self::TAG_PREFIX . $tag, $version, 86400 * 30);
                }
                $versions[$tag] = (string) $version;
            }

            if (empty($set_futures)) {
                $deferred->resolve($versions);
            } else {
                $processed = 0;
                $total_sets = count($set_futures);
                foreach ($set_futures as $f) {
                    $f->onResolve(function () use (&$processed, $total_sets, $deferred, $versions) {
                        $processed++;
                        if ($processed === $total_sets) {
                            $deferred->resolve($versions);
                        }
                    });
                }
            }
        }, fn($e) => $deferred->reject($e));

        return $deferred->future();
    }

    /**
     * Generates a unique version string for tags
     *
     * @return string
     */
    private function generateVersion() : string
    {
        return uniqid('', true);
    }

    /**
     * Deletes an item from the cache by its unique key asynchronously
     *
     * @param  string  $key  The unique cache key of the item to delete
     * @return Future        Resolves to true on success and false on failure
     */
    public function delete(string $key) : Future
    {
        return $this->adapter->delete($key);
    }

    /**
     * Wipes clean the entire cache's keys asynchronously
     *
     * @return Future Resolves to true on success and false on failure
     */
    public function clear() : Future
    {
        return $this->adapter->clear();
    }

    /**
     * Returns the underlying asynchronous cache adapter
     *
     * @return AsyncCacheAdapterInterface The adapter implementation
     */
    public function getAdapter() : AsyncCacheAdapterInterface
    {
        return $this->adapter;
    }
}
