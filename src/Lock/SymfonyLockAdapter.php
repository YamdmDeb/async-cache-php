<?php

namespace Fyennyi\AsyncCache\Lock;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class SymfonyLockAdapter implements LockInterface
{
    /** @var array<string, SharedLockInterface> */
    private array $locks = [];

    public function __construct(
        private LockFactory $factory
    ) {
    }

    public function acquire(string $key, float $ttl = 30.0, bool $blocking = false): bool
    {
        $lock = $this->factory->createLock($key, $ttl);

        if ($lock->acquire($blocking)) {
            $this->locks[$key] = $lock;
            return true;
        }

        return false;
    }

    public function release(string $key): void
    {
        if (isset($this->locks[$key])) {
            $this->locks[$key]->release();
            unset($this->locks[$key]);
        }
    }
}
