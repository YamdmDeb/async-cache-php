<?php

namespace Tests\Unit\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Middleware\RetryMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Promise\Deferred;
use Symfony\Component\Clock\MockClock;
use function React\Async\await;

class RetryMiddlewareTest extends TestCase
{
    public function testRetryMiddlewareRetries() : void
    {
        $middleware = new RetryMiddleware(max_retries: 2, initial_delay_ms: 1, logger: new NullLogger());
        $clock = new MockClock();
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $clock);

        $failCount = 0;
        $next = function () use (&$failCount) {
            $d = new Deferred();
            if ($failCount < 2) {
                $failCount++;
                $d->reject(new \Exception('fail'));
            } else {
                $d->resolve('ok');
            }

            return $d->promise();
        };

        $res = await($middleware->handle($context, $next));
        $this->assertSame('ok', $res);
        $this->assertSame(2, $failCount);
    }

    public function testRetryMiddlewareExhaustsRetries() : void
    {
        $middleware = new RetryMiddleware(max_retries: 2, initial_delay_ms: 10, logger: new NullLogger());
        $clock = new MockClock();
        $context = new CacheContext('k', fn () => null, new CacheOptions(), $clock);

        $failCount = 0;
        $next = function () use (&$failCount) {
            $failCount++;
            return \React\Promise\reject(new \Exception("Fail $failCount"));
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Fail 3');

        await($middleware->handle($context, $next));
    }
}
