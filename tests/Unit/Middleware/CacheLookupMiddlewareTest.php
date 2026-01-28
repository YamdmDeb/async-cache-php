<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Enum\CacheStrategy;
use Fyennyi\AsyncCache\Middleware\CacheLookupMiddleware;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use function React\Async\await;

class CacheLookupMiddlewareTest extends TestCase
{
    private MockObject|CacheStorage $storage;
    private MockObject|LoggerInterface $logger;
    private MockClock $clock;
    private CacheLookupMiddleware $middleware;

    protected function setUp() : void
    {
        $this->storage = $this->createMock(CacheStorage::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clock = new MockClock();
        $this->middleware = new CacheLookupMiddleware($this->storage, $this->logger);
    }

    public function testReturnsCachedDataIfFresh() : void
    {
        $item = new CachedItem('data', $this->clock->now()->getTimestamp() + 100);
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\resolve($item));
        $this->assertSame('data', await($this->middleware->handle($context, fn () => \React\Promise\resolve(null))));
    }

    public function testCallsNextOnCacheMiss() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\resolve(null));
        $next = fn () => \React\Promise\resolve('from_next');
        $this->assertSame('from_next', await($this->middleware->handle($context, $next)));
    }

    public function testBypassesOnForceRefresh() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(strategy: CacheStrategy::ForceRefresh), $this->clock);
        $this->storage->expects($this->never())->method('get');
        $next = fn () => \React\Promise\resolve('bypassed');
        $this->assertSame('bypassed', await($this->middleware->handle($context, $next)));
    }

    public function testBackgroundRefreshReturnsStaleAndCallsNext() : void
    {
        $item = new CachedItem('stale', $this->clock->now()->getTimestamp() - 10);
        $context = new CacheContext('k', fn () => null, new CacheOptions(strategy: CacheStrategy::Background), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\resolve($item));
        $nextCalled = false;
        $next = function () use (&$nextCalled) {
            $nextCalled = true;
            return \React\Promise\resolve('ignored');
        };
        $res = await($this->middleware->handle($context, $next));
        $this->assertSame('stale', $res);
        $this->assertTrue($nextCalled);
    }

    public function testXFetchTriggered() : void
    {
        $item = new CachedItem('data', $this->clock->now()->getTimestamp() + 1, generation_time: 1.0);
        $context = new CacheContext('k', fn () => null, new CacheOptions(x_fetch_beta: 1000.0), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\resolve($item));
        $next = fn () => \React\Promise\resolve('xfetch_triggered');
        $this->assertSame('xfetch_triggered', await($this->middleware->handle($context, $next)));
    }

    public function testProceedsIfItemHasTags() : void
    {
        $item = new CachedItem('data', $this->clock->now()->getTimestamp() + 100, tag_versions: ['t1' => 'v1']);
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\resolve($item));
        $next = fn () => \React\Promise\resolve('validated');
        $this->assertSame('validated', await($this->middleware->handle($context, $next)));
    }

    public function testHandlesStorageError() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\reject(new \Exception('Storage error')));
        $next = fn () => \React\Promise\resolve('fallback');
        $this->assertSame('fallback', await($this->middleware->handle($context, $next)));
    }

    public function testHandlesBackgroundFetchError() : void
    {
        $item = new CachedItem('stale', $this->clock->now()->getTimestamp() - 10);
        $context = new CacheContext('k', fn () => null, new CacheOptions(strategy: CacheStrategy::Background), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\resolve($item));
        $next = fn () => \React\Promise\reject(new \Exception('Background fail'));
        $res = await($this->middleware->handle($context, $next));
        $this->assertSame('stale', $res);
    }

    public function testHandlesPipelineErrorLogging() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\resolve(null));

        $this->logger->expects($this->atLeastOnce())->method('debug');

        $next = fn () => \React\Promise\reject(new \Exception('Pipeline fail'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Pipeline fail');
        await($this->middleware->handle($context, $next));
    }
}
