<?php

namespace Fyennyi\AsyncCache\Bridge;

use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use GuzzleHttp\Promise\Utils;
use React\Promise\Deferred as ReactDeferred;
use React\Promise\PromiseInterface as ReactPromiseInterface;

/**
 * Converts internal Future placeholders to industry-standard Promise objects
 */
class PromiseAdapter
{
    /**
     * Converts a native Future to a Guzzle Promise
     */
    public static function toGuzzle(Future $future) : GuzzlePromiseInterface
    {
        $guzzle = new GuzzlePromise();
        $future->onResolve(
            fn($v) => $guzzle->resolve($v),
            fn($r) => $guzzle->reject($r)
        );
        return $guzzle;
    }

    /**
     * Converts a native Future to a ReactPHP Promise
     */
    public static function toReact(Future $future) : ReactPromiseInterface
    {
        $deferred = new ReactDeferred();
        $future->onResolve(
            fn($v) => $deferred->resolve($v),
            fn($r) => $deferred->reject($r)
        );
        return $deferred->promise();
    }

    /**
     * Converts Futures, ReactPHP/Guzzle promises or raw values to a native Future
     *
     * @param  mixed  $value  The value or promise to convert
     * @return Future         A Future tracking the resolution
     */
    public static function toFuture(mixed $value) : Future
    {
        if ($value instanceof Future) {
            return $value;
        }

        $deferred = new Deferred();

        if ($value instanceof ReactPromiseInterface) {
            $value->then(
                fn($v) => $deferred->resolve($v),
                fn($r) => $deferred->reject($r)
            );
            return $deferred->future();
        }

        if ($value instanceof GuzzlePromiseInterface) {
            $value->then(
                fn($v) => $deferred->resolve($v),
                fn($r) => $deferred->reject($r)
            );

            // Ensure Guzzle's task queue is flushed
            if (class_exists(Utils::class)) {
                Utils::queue()->run();
            } else {
                \GuzzleHttp\Promise\queue()->run();
            }

            return $deferred->future();
        }

        // It's a raw value
        $deferred->resolve($value);
        return $deferred->future();
    }
}
