<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Enum\CacheStrategy;
use Fyennyi\AsyncCache\Middleware\CacheLookupMiddleware;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CacheLookupMiddlewareTest extends TestCase
{
    private MockObject|CacheStorage $storage;
    private CacheLookupMiddleware $middleware;

    protected function setUp() : void
    {
        $this->storage = $this->createMock(CacheStorage::class);
        $this->middleware = new CacheLookupMiddleware($this->storage, new NullLogger());
    }

    public function testReturnsCachedDataIfFresh() : void
    {
        $context = new CacheContext('k', fn()=>null, new CacheOptions());
        $item = new CachedItem('val', time() + 100);
        
        $d = new Deferred(); $d->resolve($item);
        $this->storage->method('get')->willReturn($d->future());

        // Next should not be called
        $next = fn() => (new Deferred())->future();

        $this->assertSame('val', $this->middleware->handle($context, $next)->wait());
    }

    public function testCallsNextOnCacheMiss() : void
    {
        $context = new CacheContext('k', fn()=>null, new CacheOptions());
        
        $d = new Deferred(); $d->resolve(null);
        $this->storage->method('get')->willReturn($d->future());

        $next = function() {
            $d = new Deferred(); $d->resolve('fetched'); return $d->future();
        };

        $this->assertSame('fetched', $this->middleware->handle($context, $next)->wait());
    }

    public function testBypassesOnForceRefresh() : void
    {
        $options = new CacheOptions(strategy: CacheStrategy::ForceRefresh);
        $context = new CacheContext('k', fn()=>null, $options);
        
        $this->storage->expects($this->never())->method('get');

        $next = function() {
            $d = new Deferred(); $d->resolve('fetched'); return $d->future();
        };

        $this->assertSame('fetched', $this->middleware->handle($context, $next)->wait());
    }

    public function testBackgroundRefreshReturnsStaleAndCallsNext() : void
    {
        $options = new CacheOptions(strategy: CacheStrategy::Background);
        $context = new CacheContext('k', fn()=>null, $options);
        
        // Stale item
        $item = new CachedItem('stale', time() - 100);
        $d = new Deferred(); $d->resolve($item);
        $this->storage->method('get')->willReturn($d->future());

        $called = false;
        $next = function() use (&$called) {
            $called = true;
            $d = new Deferred(); $d->resolve('fresh'); return $d->future();
        };

        // Should return stale data immediately
        $this->assertSame('stale', $this->middleware->handle($context, $next)->wait());
        
        // But next() should have been called (background fetch)
        $this->assertTrue($called);
    }
}
