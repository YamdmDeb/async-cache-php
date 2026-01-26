<?php

namespace Fyennyi\AsyncCache\Lock;

/**
 * Interface for distributed locking support
 */
interface LockInterface
{
    /**
     * Acquires the lock for a specific resource
     * 
     * @param  string  $key       The unique identifier for the lock
     * @param  float   $ttl       The lock duration in seconds
     * @param  bool    $blocking  Whether to wait for the lock to become available
     * @return bool               True if lock was successfully acquired
     */
    public function acquire(string $key, float $ttl = 30.0, bool $blocking = false) : bool;

    /**
     * Releases a previously acquired lock
     * 
     * @param  string  $key  The resource identifier to unlock
     * @return void
     */
    public function release(string $key) : void;
}
