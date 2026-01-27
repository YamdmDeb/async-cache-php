<?php

/*
 *
 *     _                          ____           _            ____  _   _ ____
 *    / \   ___ _   _ _ __   ___ / ___|__ _  ___| |__   ___  |  _ \| | | |  _ \
 *   / _ \ / __| | | | '_ \ / __| |   / _` |/ __| '_ \ / _ \ | |_) | |_| | |_) |
 *  / ___ \\__ \ |_| | | | | (__| |__| (_| | (__| | | |  __/ |  __/|  _  |  __/
 * /_/   \_\___/\__, |_| |_|\___|\____\__,_|\___|_| |_|\___| |_|   |_| |_|_|
 *              |___/
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author Serhii Cherneha
 * @link https://chernega.eu.org/
 *
 *
 */

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
     *
     * @param  Future  $future  The library-native Future instance to be adapted
     * @return GuzzlePromiseInterface A Guzzle Promise that completes when the Future is resolved or rejected
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
     *
     * @param  Future  $future  The library-native Future instance to be adapted
     * @return ReactPromiseInterface<mixed> A ReactPHP Promise that completes when the Future is resolved or rejected
     */
    public static function toReact(Future $future) : ReactPromiseInterface
    {
        $deferred = new ReactDeferred();
        $future->onResolve(
            fn($v) => $deferred->resolve($v),
            fn($r) => $deferred->reject($r instanceof \Throwable ? $r : new \RuntimeException(\is_scalar($r) || $r instanceof \Stringable ? (string)$r : 'Unknown error'))
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
            // @phpstan-ignore-next-line
            \GuzzleHttp\Promise\queue()->run();
        }

            return $deferred->future();
        }

        // It's a raw value
        $deferred->resolve($value);
        return $deferred->future();
    }
}
