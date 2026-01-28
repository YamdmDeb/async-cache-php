<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Middleware\CircuitBreakerMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use React\Promise\Deferred;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use function React\Async\await;

class CircuitBreakerMiddlewareTest extends TestCase
{
    private MockObject|CacheInterface $storage;
    private MockClock $clock;
    private CircuitBreakerMiddleware $middleware;

    protected function setUp() : void
    {
        $this->storage = $this->createMock(CacheInterface::class);
        $this->clock = new MockClock();
        $this->middleware = new CircuitBreakerMiddleware(
            storage: $this->storage,
            lock_factory: new LockFactory(new InMemoryStore()),
            failure_threshold: 2,
            retry_timeout: 60,
            prefix: 'cb:',
            logger: new NullLogger()
        );
    }

    public function testAllowsRequestWhenClosed() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturn('closed');

        $next = function () {
            $d = new Deferred();
            $d->resolve('ok');

            return $d->promise();
        };

        $this->assertSame('ok', await($this->middleware->handle($context, $next)));
    }

    public function testBlocksRequestWhenOpenAndFresh() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturnMap([
            ['cb:state:k', 'closed', 'open'],
            ['cb:last_fail:k', 0, $this->clock->now()->getTimestamp()], // failed just now
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circuit Breaker is OPEN');

        // Next should not be called, but providing a dummy just in case
        $next = fn () => (new Deferred())->promise();
        await($this->middleware->handle($context, $next));
    }

    public function testAllowsProbeWhenOpenAndExpired() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturnMap([
            ['cb:state:k', 'closed', 'open'],
            ['cb:last_fail:k', 0, $this->clock->now()->getTimestamp() - 61], // failed long ago
        ]);

        $this->storage->expects($this->exactly(2))->method('set')->willReturnCallback(function ($k, $v) {
            if ('cb:state:k' === $k && 'closed' === $v) {
                return true;
            }
            if ('cb:fail:k' === $k && 0 === $v) {
                return true;
            }

            return true;
        });

        $next = function () {
            $d = new Deferred();
            $d->resolve('ok');

            return $d->promise();
        };

        $this->assertSame('ok', await($this->middleware->handle($context, $next)));
    }

    public function testRecordsFailureAndOpensCircuit() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturnMap([
            ['cb:state:k', 'closed', 'closed'],
            ['cb:fail:k', 0, 1], // already 1 failure
        ]);

        $called = 0;
        $this->storage->method('set')->willReturnCallback(function ($key, $val) use (&$called) {
            $called++;
            if (1 === $called) {
                $this->assertSame('cb:fail:k', $key);
            }
            if (2 === $called) {
                $this->assertSame('cb:state:k', $key);
            }

            return true;
        });

        $next = function () {
            $d = new Deferred();
            $d->reject(new \Exception('fail'));

            return $d->promise();
        };

        try {
            await($this->middleware->handle($context, $next));
        } catch (\Exception $e) {
        }

        $this->assertGreaterThanOrEqual(2, $called);
    }

    public function testResetsOnSuccess() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturn('closed');

        $this->storage->method('set')->willReturnCallback(function ($k, $v) {
            if ('cb:state:k' === $k) {
                $this->assertSame('closed', $v);
            }
            if ('cb:fail:k' === $k) {
                $this->assertSame(0, $v);
            }

            return true;
        });

        $next = function () {
            $d = new Deferred();
            $d->resolve('ok');

            return $d->promise();
        };

        await($this->middleware->handle($context, $next));
    }

    public function testBlocksProbeIfLockBusy() : void
    {
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $this->clock);
        $this->storage->method('get')->willReturnMap([
            ['cb:last_fail:k', 0, $this->clock->now()->getTimestamp() - 61], // Expired, so HALF-OPEN
        ]);

        // Mock lock factory to return a lock that fails to acquire
        $lock = $this->createMock(\Symfony\Component\Lock\SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);

        $lf = $this->createMock(\Symfony\Component\Lock\LockFactory::class);
        $lf->method('createLock')->willReturn($lock);

        // Inject new lock factory
        $ref = new \ReflectionClass($this->middleware);
        $prop = $ref->getProperty('lock_factory');
        $prop->setAccessible(true);
        $prop->setValue($this->middleware, $lf);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circuit Breaker is HALF-OPEN');

        await($this->middleware->handle($context, fn () => null));
    }
}
