<?php

namespace Fyennyi\AsyncCache\Core;

use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use React\Promise\PromiseInterface as ReactPromiseInterface;

/**
 * Manages the resolution state of a Future
 */
class Deferred
{
    private Future $future;

    /**
     * @param callable|null $waitFn Optional function to drive resolution on wait()
     */
    public function __construct(callable $waitFn = null)
    {
        $this->future = new Future($waitFn);
    }

    /**
     * Returns the future controlled by this deferred
     *
     * @return Future
     */
    public function future() : Future
    {
        return $this->future;
    }

    /**
     * Fulfills the future with a success value
     *
     * @param  mixed  $value  The result value
     * @return void
     */
    public function resolve(mixed $value) : void
    {
        $this->future->resolve($value);
    }

    /**
     * Rejects the future with a failure reason
     *
     * @param  mixed  $reason  The failure reason
     * @return void
     */
    public function reject(mixed $reason) : void
    {
        $this->future->reject($reason);
    }

    /**
     * Static helper to wrap external promises into a native Future
     *
     * @param  mixed  $source  Existing promise (Guzzle or React)
     * @return Future          New native future representing the source
     */
    public static function fromPromise(mixed $source) : Future
    {
        if ($source instanceof Future) {
            return $source;
        }

        $deferred = new self();

        if ($source instanceof GuzzlePromiseInterface || $source instanceof ReactPromiseInterface) {
            $source->then(
                fn($v) => $deferred->resolve($v),
                fn($r) => $deferred->reject($r)
            );
        } else {
            $deferred->resolve($source);
        }

        return $deferred->future();
    }
}
