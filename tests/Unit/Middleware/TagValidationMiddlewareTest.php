<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Middleware\TagValidationMiddleware;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use function React\Async\await;

class TagValidationMiddlewareTest extends TestCase
{
    private MockObject|CacheStorage $storage;
    private MockClock $clock;
    private TagValidationMiddleware $middleware;

    protected function setUp() : void
    {
        $this->storage = $this->createMock(CacheStorage::class);
        $this->clock = new MockClock();
        $this->middleware = new TagValidationMiddleware($this->storage, new NullLogger());
    }

    public function testReturnsNextIfNoStaleItem() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $next = fn () => \React\Promise\resolve('from_next');

        $this->assertSame('from_next', await($this->middleware->handle($context, $next)));
    }

    public function testReturnsNextIfNoTagsInItem() : void
    {
        $item = new CachedItem('data', $this->clock->now()->getTimestamp() + 100);
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $context->stale_item = $item;

        $next = fn () => \React\Promise\resolve('from_next');
        $this->assertSame('from_next', await($this->middleware->handle($context, $next)));
    }

    public function testReturnsItemIfFreshAndTagsValid() : void
    {
        $item = new CachedItem('fresh_data', $this->clock->now()->getTimestamp() + 100, tag_versions: ['t1' => 'v1']);
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $context->stale_item = $item;

        $this->storage->expects($this->once())
            ->method('fetchTagVersions')
            ->with(['t1'])
            ->willReturn(\React\Promise\resolve(['t1' => 'v1']));

        $next = $this->getMockBuilder(\stdClass::class)->addMethods(['__invoke'])->getMock();
        $next->expects($this->never())->method('__invoke');

        $this->assertSame('fresh_data', await($this->middleware->handle($context, $next)));
    }

    public function testInvalidatesStaleItemIfTagMismatch() : void
    {
        $item = new CachedItem('data', $this->clock->now()->getTimestamp() + 100, tag_versions: ['t1' => 'v1']);
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $context->stale_item = $item;

        $this->storage->method('fetchTagVersions')->willReturn(\React\Promise\resolve(['t1' => 'v2'])); // Mismatch

        $next = function ($ctx) {
            $this->assertNull($ctx->stale_item);
            return \React\Promise\resolve('invalidated');
        };

        $this->assertSame('invalidated', await($this->middleware->handle($context, $next)));
    }

    public function testProceedsIfTagsValidButItemExpired() : void
    {
        $item = new CachedItem('expired_data', $this->clock->now()->getTimestamp() - 10, tag_versions: ['t1' => 'v1']);
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $context->stale_item = $item;

        $this->storage->method('fetchTagVersions')->willReturn(\React\Promise\resolve(['t1' => 'v1']));

        $next = function ($ctx) {
            $this->assertNotNull($ctx->stale_item);
            return \React\Promise\resolve('expired_but_valid_tags');
        };

        $this->assertSame('expired_but_valid_tags', await($this->middleware->handle($context, $next)));
    }

    public function testHandlesTagFetchError() : void
    {
        $item = new CachedItem('data', $this->clock->now()->getTimestamp() + 100, tag_versions: ['t1' => 'v1']);
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $context->stale_item = $item;

        $this->storage->method('fetchTagVersions')->willReturn(\React\Promise\reject(new \Exception('Fetch fail')));

        $next = function ($ctx) {
            $this->assertNull($ctx->stale_item);
            return \React\Promise\resolve('conservatively_invalidated');
        };

        $this->assertSame('conservatively_invalidated', await($this->middleware->handle($context, $next)));
    }
}
