<?php

namespace Fyennyi\AsyncCache\Bridge;

use Fyennyi\AsyncCache\Core\Future;
use GuzzleHttp\Promise\Promise as GuzzlePromise;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use React\Promise\Deferred as ReactDeferred;
use React\Promise\PromiseInterface as ReactPromiseInterface;

/**
 * Bridges library-native Futures to external promise ecosystems.
 */
class PromiseAdapter
{
    /**
     * Converts Future to a Guzzle Promise with automatic event loop driving on wait()
     */
    public static function toGuzzle(Future $future): GuzzlePromiseInterface
    {
        $guzzle = new GuzzlePromise(function () use ($future) {
            $future->wait();
        });

        $future->then(
            fn($v) => $guzzle->resolve($v),
            fn($r) => $guzzle->reject($r)
        );

        return $guzzle;
    }

    /**
     * Converts Future to a ReactPHP Promise
     */
    public static function toReact(Future $future): ReactPromiseInterface
    {
        $deferred = new ReactDeferred();
        
        $future->then(
            fn($v) => $deferred->resolve($v),
            fn($r) => $deferred->reject($r)
        );

        return $deferred->promise();
    }
}
