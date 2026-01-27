<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Middleware\AsyncLockMiddleware;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class AsyncLockMiddlewareTest extends TestCase
{
    private MockObject|CacheStorage $storage;
    private LockFactory $lockFactory;
    private AsyncLockMiddleware $middleware;

    protected function setUp() : void
    {
        $this->storage = $this->createMock(CacheStorage::class);
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->middleware = new AsyncLockMiddleware(
            $this->lockFactory,
            $this->storage,
            new NullLogger()
        );
    }

    public function testAcquiresLockAndProceeds() : void
    {
        $context = new CacheContext('k', fn()=>null, new CacheOptions());
        
        $next = function() {
            $d = new Deferred(); $d->resolve('ok'); return $d->future();
        };

        $this->assertSame('ok', $this->middleware->handle($context, $next)->wait());
    }

    public function testReturnsStaleIfLockedAndStaleAvailable() : void
    {
        // Acquire lock manually first to simulate busy
        $lock = $this->lockFactory->createLock('lock:k');
        $lock->acquire();

        $item = new CachedItem('stale', time() - 10);
        $context = new CacheContext('k', fn()=>null, new CacheOptions());
        $context->stale_item = $item;

        // Next should not be called
        $next = fn() => (new Deferred())->future(); // dummy

        $this->assertSame('stale', $this->middleware->handle($context, $next)->wait());
    }
}
