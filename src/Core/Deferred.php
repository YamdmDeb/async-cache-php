<?php

namespace Fyennyi\AsyncCache\Core;

/**
 * Manages the state of a Future.
 */
class Deferred
{
    private Future $future;

    public function __construct(callable $waitFn = null)
    {
        $this->future = new Future($waitFn);
    }

    public function future(): Future
    {
        return $this->future;
    }

    public function resolve(mixed $value): void
    {
        $this->future->resolve($value);
    }

    public function reject(mixed $reason): void
    {
        $this->future->reject($reason);
    }
}