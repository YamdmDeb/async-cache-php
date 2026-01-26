<?php

namespace Fyennyi\AsyncCache\Scheduler;

use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use React\Promise\PromiseInterface;
use function React\Promise\Timer\resolve as delay;

/**
 * Bridge between ReactPHP Loop and native Futures
 */
class ReactScheduler implements SchedulerInterface
{
    public function delay(float $seconds): Future
    {
        $deferred = new Deferred(fn() => \React\EventLoop\Loop::run());
        
        delay($seconds)->then(
            fn() => $deferred->resolve(null)
        );

        return $deferred->future();
    }

    public function resolve(mixed $value): Future
    {
        $deferred = new Deferred();
        $deferred->resolve($value);
        return $deferred->future();
    }

    public static function isSupported(): bool
    {
        return interface_exists(PromiseInterface::class) && function_exists('React\Promise\Timer\resolve');
    }
}