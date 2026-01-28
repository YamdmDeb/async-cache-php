<?php

namespace Tests\Unit\Core;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Pipeline;
use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use Symfony\Component\Clock\MockClock;
use function React\Async\await;

class PipelineTest extends TestCase
{
    public function testExecutesMiddlewaresInOrder() : void
    {
        $log = [];
        $clock = new MockClock();

        $m1 = $this->createMock(MiddlewareInterface::class);
        $m1->method('handle')->willReturnCallback(function ($ctx, $next) use (&$log) {
            $log[] = 'm1_in';
            $res = $next($ctx);
            $log[] = 'm1_out';
            return $res;
        });

        $m2 = $this->createMock(MiddlewareInterface::class);
        $m2->method('handle')->willReturnCallback(function ($ctx, $next) use (&$log) {
            $log[] = 'm2_in';
            $res = $next($ctx);
            $log[] = 'm2_out';
            return $res;
        });

        $pipeline = new Pipeline([$m1, $m2]);
        $context = new CacheContext('key', fn () => 'val', new CacheOptions(), $clock);

        $destination = function ($ctx) use (&$log) {
            $log[] = 'dest';
            $d = new Deferred();
            $d->resolve('final');

            return $d->promise();
        };

        $result = await($pipeline->send($context, $destination));
        $this->assertSame('final', $result);
        $this->assertSame(['m1_in', 'm2_in', 'dest', 'm2_out', 'm1_out'], $log);
    }

    public function testCatchesMiddlewareExceptions() : void
    {
        $m1 = $this->createMock(MiddlewareInterface::class);
        $m1->method('handle')->willThrowException(new \Exception('Middleware Failed'));
        $clock = new MockClock();

        $pipeline = new Pipeline([$m1]);
        $context = new CacheContext('key', fn () => 'val', new CacheOptions(), $clock);

        $future = $pipeline->send($context, fn () => (new Deferred())->promise());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Middleware Failed');

        await($future);
    }

    public function testCatchesSyncExceptionInDestination() : void
    {
        $pipeline = new Pipeline([]);
        $clock = new MockClock();
        $context = new CacheContext('key', fn () => 'val', new CacheOptions(), $clock);

        $future = $pipeline->send($context, function () {
            throw new \Exception('Destination sync fail');
        });

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Destination sync fail');

        await($future);
    }
}
