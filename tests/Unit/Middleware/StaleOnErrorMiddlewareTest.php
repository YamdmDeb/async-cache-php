<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Middleware\StaleOnErrorMiddleware;
use Fyennyi\AsyncCache\Model\CachedItem;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Promise\Deferred;
use Symfony\Component\Clock\MockClock;
use function React\Async\await;

class StaleOnErrorMiddlewareTest extends TestCase
{
    public function testStaleOnErrorReturnsStaleOnFailure() : void
    {
        $middleware = new StaleOnErrorMiddleware(new NullLogger());
        $clock = new MockClock();

        $context = new CacheContext('k', fn () => null, new CacheOptions(), $clock);
        $context->stale_item = new CachedItem('stale', $clock->now()->getTimestamp() - 10);

        $next = function () {
            $d = new Deferred();
            $d->reject(new \Exception('fail'));
            return $d->promise();
        };

        $res = await($middleware->handle($context, $next));
        $this->assertSame('stale', $res);
    }

    public function testStaleOnErrorRejectsIfNoStale() : void
    {
        $middleware = new StaleOnErrorMiddleware(new NullLogger());
        $clock = new MockClock();

        $context = new CacheContext('k', fn () => null, new CacheOptions(), $clock);
        // No stale item

        $next = function () {
            $d = new Deferred();
            $d->reject(new \Exception('fail'));
            return $d->promise();
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('fail');

        await($middleware->handle($context, $next));
    }
}
