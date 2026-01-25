<?php

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

    public function get(string $key, mixed $default = null): mixed
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

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($this->adapters as $adapter) {
            $success = $adapter->set($key, $value, $ttl) && $success;
        }
        return $success;
    }

    public function delete(string $key): bool
    {
        $success = true;
        foreach ($this->adapters as $adapter) {
            $success = $adapter->delete($key) && $success;
        }
        return $success;
    }

    public function clear(): bool
    {
        $success = true;
        foreach ($this->adapters as $adapter) {
            $success = $adapter->clear() && $success;
        }
        return $success;
    }

    public function has(string $key): bool
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->has($key)) {
                return true;
            }
        }
        return false;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // For simplicity, we implement via individual gets,
        // but a more efficient implementation would follow the get() logic
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            $success = $this->set($key, $value, $ttl) && $success;
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }
        return $success;
    }
}
