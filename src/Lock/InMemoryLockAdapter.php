<?php

namespace Fyennyi\AsyncCache\Lock;

/**
 * Local in-memory lock implementation
 */
class InMemoryLockAdapter implements LockInterface
{
    /** @var array<string, int> Map of key => expiry_timestamp */
    private array $locks = [];

    /**
     * Acquires a lock in memory
     *
     * @param  string  $key       Resource key
     * @param  float   $ttl       Expiry in seconds
     * @param  bool    $blocking  Not supported for in-memory (always returns false if busy)
     * @return bool               True if acquired
     */
    public function acquire(string $key, float $ttl = 30.0, bool $blocking = false) : bool
    {
        if (isset($this->locks[$key]) && $this->locks[$key] > time()) {
            return false;
        }

        $this->locks[$key] = time() + (int)$ttl;
        return true;
    }

    /**
     * Releases the memory lock
     *
     * @param  string  $key  Resource key
     * @return void
     */
    public function release(string $key) : void
    {
        unset($this->locks[$key]);
    }
}
