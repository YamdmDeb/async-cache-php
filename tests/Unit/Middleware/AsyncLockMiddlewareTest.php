<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Middleware\AsyncLockMiddleware;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use function React\Async\await;

class AsyncLockMiddlewareTest extends TestCase
{
    private MockObject|CacheStorage $storage;
    private LockFactory $lockFactory;
    private MockClock $clock;
    private AsyncLockMiddleware $middleware;

    protected function setUp() : void
    {
        $this->storage = $this->createMock(CacheStorage::class);
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->clock = new MockClock();
        $this->middleware = new AsyncLockMiddleware($this->lockFactory, $this->storage, new NullLogger());
    }

    public function testAcquiresLockAndProceeds() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->assertSame('ok', await($this->middleware->handle($context, fn () => \React\Promise\resolve('ok'))));
    }

    public function testReturnsStaleIfLockedAndStaleAvailable() : void
    {
        $lock = $this->lockFactory->createLock('lock:k');
        $lock->acquire();
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $context->stale_item = new CachedItem('stale', $this->clock->now()->getTimestamp() - 10);
        $this->assertSame('stale', await($this->middleware->handle($context, fn () => null)));
    }

    public function testLockWaitAndSuccess() : void
    {
        $lock = $this->lockFactory->createLock('lock:k');
        $lock->acquire();
        \React\EventLoop\Loop::addTimer(0.01, fn () => $lock->release());
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturn(\React\Promise\resolve(null));
        $this->assertSame('ok', await($this->middleware->handle($context, fn () => \React\Promise\resolve('ok'))));
    }

    public function testLockTimeout() : void
    {
        $lock = $this->lockFactory->createLock('lock:k');
        $lock->acquire();

        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);

        \React\EventLoop\Loop::addTimer(0.01, fn () => $this->clock->sleep(11.0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not acquire lock for key: k (Timeout)');

        await($this->middleware->handle($context, fn () => null));
    }

    public function testHandleWithLockSyncException() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->expectException(\Exception::class);
        await($this->middleware->handle($context, function () {
            throw new \Exception('Sync fail');
        }));
    }

    public function testLockStorageError() : void
    {
        $lock = $this->lockFactory->createLock('lock:k');
        $lock->acquire();
        \React\EventLoop\Loop::addTimer(0.01, fn () => $lock->release());
        $this->storage->method('get')->willReturn(\React\Promise\reject(new \Exception('Storage error')));
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->expectException(\Exception::class);
        await($this->middleware->handle($context, fn () => null));
    }

    public function testReleaseLockTwice() : void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning');
        $middleware = new AsyncLockMiddleware($this->lockFactory, $this->storage, $logger);
        $ref = new \ReflectionClass($middleware);
        $method = $ref->getMethod('releaseLock');
        $method->setAccessible(true);
        $method->invoke($middleware, 'nonexistent');
        $this->assertTrue(true);
    }

    public function testHandleWithLockAlreadyFresh() : void
    {
        $lock = $this->lockFactory->createLock('lock:k');
        $lock->acquire();
        \React\EventLoop\Loop::addTimer(0.01, fn () => $lock->release());
        $this->storage->method('get')->willReturn(\React\Promise\resolve(new CachedItem('fresh', $this->clock->now()->getTimestamp() + 100)));
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $res = await($this->middleware->handle($context, fn () => null));
        $this->assertSame('fresh', $res);
    }

    public function testHandleAsyncExceptionLog() : void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('debug');
        $middleware = new AsyncLockMiddleware($this->lockFactory, $this->storage, $logger);
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->expectException(\Exception::class);
        await($middleware->handle($context, fn () => \React\Promise\reject(new \Exception('Async fail'))));
    }

    public function testLockInnerErrorCatch() : void
    {
        $lock = $this->lockFactory->createLock('lock:k');
        $lock->acquire();
        \React\EventLoop\Loop::addTimer(0.01, fn () => $lock->release());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error')->with($this->stringContains('LOCK_INNER_ERROR'));

        $middleware = new AsyncLockMiddleware($this->lockFactory, $this->storage, $logger);
        $this->storage->method('get')->willReturn(\React\Promise\resolve(null));

        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);

        $next = function () {
            throw new \Error('Inner sync throw');
        };

        $this->expectException(\Throwable::class);
        await($middleware->handle($context, $next));
    }

    public function testLockStorageErrorCatch() : void
    {
        $lock = $this->lockFactory->createLock('lock:k');
        $lock->acquire();

        \React\EventLoop\Loop::addTimer(0.01, fn () => $lock->release());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error')->with($this->stringContains('LOCK_STORAGE_ERROR'));

        $middleware = new AsyncLockMiddleware($this->lockFactory, $this->storage, $logger);

        $this->storage->method('get')->willReturn(\React\Promise\reject(new \Exception('Storage failed')));
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Storage failed');
        await($middleware->handle($context, fn () => null));
    }

    public function testRetryErrorCatch() : void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);

        $lockFactory = $this->createMock(LockFactory::class);
        $callCount = 0;
        $lockFactory->method('createLock')->willReturnCallback(function () use (&$callCount, $lock) {
            $callCount++;
            if (2 === $callCount) {
                throw new \RuntimeException('Forced retry failure');
            }
            return $lock;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('LOCK_RETRY_ERROR'));

        $middleware = new AsyncLockMiddleware($lockFactory, $this->storage, $logger);

        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);

        $promise = $middleware->handle($context, fn () => null);

        try {
            await($promise);
        } catch (\Throwable $e) {
            $this->assertSame('Forced retry failure', $e->getMessage());
        }
    }
}
