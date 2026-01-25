<?php

namespace Fyennyi\AsyncCache\Storage;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Serializer\PhpSerializer;
use Fyennyi\AsyncCache\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Service responsible for safe and optimized interactions with the PSR-16 adapter
 */
class CacheStorage
{
    private const TAG_PREFIX = 'tag_v:';
    private SerializerInterface $serializer;

    public function __construct(
        private CacheInterface $adapter,
        private LoggerInterface $logger,
        ?SerializerInterface $serializer = null
    ) {
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    /**
     * Retrieves an item from the cache, handles fail-safe, decompression and tag validation
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

            // Tag Validation
            if (!empty($cached_item->tagVersions)) {
                $currentVersions = $this->getTagVersions(array_keys($cached_item->tagVersions));
                foreach ($cached_item->tagVersions as $tag => $savedVersion) {
                    if (($currentVersions[$tag] ?? null) !== $savedVersion) {
                        $this->logger->debug('AsyncCache TAG_INVALID: tag version mismatch', ['key' => $key, 'tag' => $tag]);
                        return null;
                    }
                }
            }

            // Handle decompression
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
     * Stores an item in the cache, handles compression, fail-safe and tags
     */
    public function set(string $key, mixed $data, CacheOptions $options, float $generationTime = 0.0): bool
    {
        try {
            $logical_ttl = $options->ttl;
            $physical_ttl = $logical_ttl + $options->stale_grace_period;
            $is_compressed = false;

            // Fetch current tag versions
            $tagVersions = [];
            if (!empty($options->tags)) {
                $tagVersions = $this->getTagVersions($options->tags, true);
            }

            if ($options->compression) {
                $serialized_data = $this->serializer->serialize($data);
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
                generationTime: $generationTime,
                tagVersions: $tagVersions
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
     * Invalidates specific tags by changing their versions
     */
    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->adapter->set(self::TAG_PREFIX . $tag, $this->generateVersion());
        }
        $this->logger->info('AsyncCache TAGS_INVALIDATED', ['tags' => $tags]);
    }

    /**
     * Fetches current versions for a set of tags
     */
    private function getTagVersions(array $tags, bool $createMissing = false): array
    {
        $keys = array_map(fn($t) => self::TAG_PREFIX . $t, $tags);
        $rawVersions = $this->adapter->getMultiple($keys);

        $versions = [];
        foreach ($tags as $tag) {
            $version = $rawVersions[self::TAG_PREFIX . $tag] ?? null;
            if ($version === null && $createMissing) {
                $version = $this->generateVersion();
                $this->adapter->set(self::TAG_PREFIX . $tag, $version, 86400 * 30);
            }
            $versions[$tag] = (string) $version;
        }

        return $versions;
    }

    private function generateVersion(): string
    {
        return uniqid('', true);
    }

    public function delete(string $key): bool { return $this->adapter->delete($key); }
    public function clear(): bool { return $this->adapter->clear(); }
    public function getAdapter(): CacheInterface { return $this->adapter; }
}