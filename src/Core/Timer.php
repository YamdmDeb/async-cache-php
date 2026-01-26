<?php

namespace Fyennyi\AsyncCache\Core;

use React\Promise\Timer\resolve as reactDelay;

/**
 * High-level timer for non-blocking asynchronous delays
 */
class Timer
{
    /**
     * Creates a non-blocking delay that resolves into a Future
     *
     * @param  float  $seconds  Seconds to wait
     * @return Future           Future that resolves when time passes
     */
    public static function delay(float $seconds) : Future
    {
        $deferred = new Deferred();

        if (function_exists('React\Promise\Timer\resolve')) {
            reactDelay($seconds)->then(fn() => $deferred->resolve(null));
        } else {
            // Fallback for extreme cases (should not happen in proper install)
            usleep((int)($seconds * 1000000));
            $deferred->resolve(null);
        }

        return $deferred->future();
    }
}