<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Middleware\SourceFetchMiddleware;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use function React\Async\await;

class SourceFetchMiddlewareTest extends TestCase
{
    private MockObject|CacheStorage $storage;
    private MockObject|LoggerInterface $logger;
    private MockClock $clock;
    private SourceFetchMiddleware $middleware;

    protected function setUp() : void
    {
        $this->storage = $this->createMock(CacheStorage::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clock = new MockClock();
        $this->middleware = new SourceFetchMiddleware($this->storage, $this->logger);
    }

    public function testSourceFetchFetchesAndCaches() : void
    {
        $context = new CacheContext('k', fn () => 'fresh', new CacheOptions(), $this->clock);
        $this->storage->expects($this->once())->method('set')->willReturn(\React\Promise\resolve(true));

        $res = await($this->middleware->handle($context, fn () => \React\Promise\resolve('fresh')));
        $this->assertSame('fresh', $res);
    }

    public function testSourceFetchHandlesException() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('oops');

        await($this->middleware->handle($context, fn () => \React\Promise\reject(new \Exception('oops'))));
    }

    public function testSourceFetchHandlesRejectedFuture() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->logger->method('debug');
        $this->expectException(\Exception::class);
        await($this->middleware->handle($context, fn () => \React\Promise\reject(new \Exception('Async rejected'))));
    }

    public function testSourceFetchHandlesSyncException() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sync error');
        await($this->middleware->handle($context, function () {
            throw new \Exception('Sync error');
        }));
    }

    public function testSourceFetchLogsPersistenceError() : void
    {
        $context = new CacheContext('k', fn () => 'fresh', new CacheOptions(), $this->clock);
        $this->storage->method('set')->willReturn(\React\Promise\reject(new \Exception('Persistence fail')));
        $this->logger->expects($this->atLeastOnce())->method('error');

        $res = await($this->middleware->handle($context, fn () => \React\Promise\resolve('fresh')));
        $this->assertSame('fresh', $res);
    }
}
