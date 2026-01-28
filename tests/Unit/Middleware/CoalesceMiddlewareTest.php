<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Middleware\CoalesceMiddleware;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use Symfony\Component\Clock\MockClock;
use function React\Async\await;

class CoalesceMiddlewareTest extends TestCase
{
    public function testCoalesceMiddleware() : void
    {
        $middleware = new CoalesceMiddleware();
        $clock = new MockClock();
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $clock);

        $deferred = new Deferred();
        $callCount = 0;
        $next = function () use ($deferred, &$callCount) {
            $callCount++;
            return $deferred->promise();
        };

        $p1 = $middleware->handle($context, $next);
        $p2 = $middleware->handle($context, $next);

        $this->assertSame($p1, $p2);
        $this->assertSame(1, $callCount);

        $deferred->resolve('done');
        $this->assertSame('done', await($p1));
    }

    public function testCoalesceHandlesFailure() : void
    {
        $middleware = new CoalesceMiddleware();
        $clock = new MockClock();
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $clock);

        $deferred = new Deferred();
        $p1 = $middleware->handle($context, fn () => $deferred->promise());

        $deferred->reject(new \Exception('fail'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('fail');
        await($p1);
    }
}
