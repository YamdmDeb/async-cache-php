<?php

namespace Fyennyi\AsyncCache\Bridge;

use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use React\Promise\Deferred as ReactDeferred;
use React\Promise\PromiseInterface as ReactPromiseInterface;

/**
 * Converts internal Future placeholders to industry-standard Promise objects.
 */
class PromiseAdapter
{
    /**
     * Converts a native Future to a Guzzle Promise.
     */
    public static function toGuzzle(Future $future) : \GuzzleHttp\Promise\PromiseInterface
    {
        $guzzle = new GuzzlePromise();
        $future->onResolve(
            fn($v) => $guzzle->resolve($v),
            fn($r) => $guzzle->reject($r)
        );
        return $guzzle;
    }

    /**
     * Converts a native Future to a ReactPHP Promise.
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
     * Converts a ReactPHP Promise to a native Future.
     */
    public static function toFuture(ReactPromiseInterface $promise) : Future
    {
        $deferred = new Deferred();
        $promise->then(
            fn($v) => $deferred->resolve($v),
            fn($r) => $deferred->reject($r)
        );
        return $deferred->future();
    }
}
