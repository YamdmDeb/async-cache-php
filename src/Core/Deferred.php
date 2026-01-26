<?php

namespace Fyennyi\AsyncCache\Core;

/**
 * Manages the resolution state of a Future.
 * Acts as the controller/producer side of the asynchronous operation.
 */
class Deferred
{
    private Future $future;

    public function __construct()
    {
        $this->future = new Future();
    }

    /**
     * Returns the future controlled by this deferred.
     *
     * @return Future
     */
    public function future() : Future
    {
        return $this->future;
    }

    /**
     * Fulfills the future with a success value.
     *
     * @param  mixed  $value  The result value
     * @return void
     */
    public function resolve(mixed $value) : void
    {
        $this->future->fulfill($value);
    }

    /**
     * Rejects the future with a failure reason.
     *
     * @param  mixed  $reason  The failure reason
     * @return void
     */
    public function reject(mixed $reason) : void
    {
        $this->future->notifyFailure($reason);
    }
}