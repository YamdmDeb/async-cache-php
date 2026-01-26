<?php

namespace Fyennyi\AsyncCache\Lock;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Adapter for Symfony Lock component
 */
class SymfonyLockAdapter implements LockInterface
{
    /** @var array<string, SharedLockInterface>  Map of active lock objects */
    private array $locks = [];

    /**
     * @param  LockFactory  $factory  Symfony Lock Factory instance
     */
    public function __construct(
        private LockFactory $factory
    ) {
    }

    /**
     * Acquires a lock using Symfony drivers
     *
     * @param  string  $key       Resource key
     * @param  float   $ttl       Expiry in seconds
     * @param  bool    $blocking  Whether to block current process until available
     * @return bool               True if acquired
     */
    public function acquire(string $key, float $ttl = 30.0, bool $blocking = false) : bool
    {
        $lock = $this->factory->createLock($key, $ttl);

        if ($lock->acquire($blocking)) {
            $this->locks[$key] = $lock;
            return true;
        }

        return false;
    }

    /**
     * Releases the active Symfony lock
     *
     * @param  string  $key  Resource key
     * @return void
     */
    public function release(string $key) : void
    {
        if (isset($this->locks[$key])) {
            $this->locks[$key]->release();
            unset($this->locks[$key]);
        }
    }
}
