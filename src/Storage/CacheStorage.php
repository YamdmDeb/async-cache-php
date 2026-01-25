<?php

namespace Fyennyi\AsyncCache\Storage;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Model\CachedItem;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Service responsible for safe and optimized interactions with the PSR-16 adapter
 */
class CacheStorage
{
    public function __construct(
        private CacheInterface $adapter,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Retrieves an item from the cache, handles fail-safe and decompression
     */
    public function get(string $key, CacheOptions $options): ?CachedItem
    {
        try {
            $cached_item = $this->adapter->get($key);
            
            if ($cached_item === null) {
                return null;
            }

            // Handle backward compatibility (old array format)
            if (is_array($cached_item) && array_key_exists('d', $cached_item) && array_key_exists('e', $cached_item)) {
                $cached_item = new CachedItem($cached_item['d'], $cached_item['e']);
            }

            if (!$cached_item instanceof CachedItem) {
                return null;
            }

            // Handle decompression
            if ($cached_item->isCompressed && is_string($cached_item->data)) {
                $decompressed_data = @gzuncompress($cached_item->data);
                if ($decompressed_data !== false) {
                    $data = unserialize($decompressed_data);
                    return new CachedItem(
                        data: $data,
                        logicalExpireTime: $cached_item->logicalExpireTime,
                        version: $cached_item->version,
                        isCompressed: false,
                        generationTime: $cached_item->generationTime
                    );
                }
                
                $this->logger->error('AsyncCache DECOMPRESSION_ERROR: failed to decompress data', ['key' => $key]);
                return null;
            }

            return $cached_item;

        } catch (\Throwable $e) {
            if ($options->fail_safe) {
                $this->logger->error('AsyncCache CACHE_GET_ERROR: adapter failed', [
                    'key' => $key,
                    'exception' => $e->getMessage()
                ]);
                return null;
            }
            throw $e;
        }
    }

    /**
     * Stores an item in the cache, handles compression and fail-safe
     */
    public function set(string $key, mixed $data, CacheOptions $options, float $generationTime = 0.0): bool
    {
        try {
            $logical_ttl = $options->ttl;
            $physical_ttl = $logical_ttl + $options->stale_grace_period;
            $is_compressed = false;

            if ($options->compression) {
                $serialized_data = serialize($data);
                if (strlen($serialized_data) >= $options->compression_threshold) {
                    $compressed_data = @gzcompress($serialized_data);
                    if ($compressed_data !== false) {
                        $data = $compressed_data;
                        $is_compressed = true;
                        $this->logger->debug('AsyncCache COMPRESSION: data compressed', [
                            'key' => $key,
                            'original_size' => strlen($serialized_data),
                            'compressed_size' => strlen($compressed_data)
                        ]);
                    }
                }
            }

            $item = new CachedItem(
                data: $data,
                logicalExpireTime: time() + $logical_ttl,
                isCompressed: $is_compressed,
                generationTime: $generationTime
            );

            return $this->adapter->set($key, $item, $physical_ttl);

        } catch (\Throwable $e) {
            if ($options->fail_safe) {
                $this->logger->error('AsyncCache CACHE_SET_ERROR: adapter failed', [
                    'key' => $key,
                    'exception' => $e->getMessage()
                ]);
                return false;
            }
            throw $e;
        }
    }

    /**
     * Proxy methods for simple operations
     */
    public function delete(string $key): bool { return $this->adapter->delete($key); }
    public function clear(): bool { return $this->adapter->clear(); }
    public function getAdapter(): CacheInterface { return $this->adapter; }
}