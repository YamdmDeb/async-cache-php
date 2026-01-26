<?php

namespace Fyennyi\AsyncCache\Core;

/**
 * The internal "currency" of the library representing a value that will eventually be available.
 * Refactored to be a passive value placeholder (Container).
 */
class Future
{
    /** @var array<callable> List of callbacks waiting for resolution */
    private array $listeners = [];

    /** @var mixed The resolved value or rejection reason */
    private mixed $result = null;

    /** @var bool Whether the future has been successfully resolved */
    private bool $isResolved = false;

    /** @var bool Whether the future has been rejected */
    private bool $isRejected = false;

    /**
     * Internal constructor. Only Deferred should create instances.
     */
    public function __construct() {}

    /**
     * Attaches a listener to be called when the value is ready.
     * Unlike Promise::then, this does not return a new Future to avoid deadlocks and chaining complexity.
     *
     * @param  callable|null  $onFulfilled  Success handler receiving the result
     * @param  callable|null  $onRejected   Failure handler receiving the error
     * @return self                         Returns itself for fluent interface (but not chaining new futures)
     */
    public function onResolve(?callable $onFulfilled = null, ?callable $onRejected = null) : self
    {
        if ($this->isResolved) {
            if ($onFulfilled) $onFulfilled($this->result);
        } elseif ($this->isRejected) {
            if ($onRejected) $onRejected($this->result);
        } else {
            $this->listeners[] = function() use ($onFulfilled, $onRejected) {
                if ($this->isRejected) {
                    if ($onRejected) $onRejected($this->result);
                } else {
                    if ($onFulfilled) $onFulfilled($this->result);
                }
            };
        }
        return $this;
    }

    /**
     * Synchronously waits for the value to arrive.
     * Uses ReactPHP async/await mechanism under the hood to drive the loop if needed.
     *
     * @return mixed  The resolved value
     * @throws \Throwable If the operation failed
     */
    public function wait() : mixed
    {
        // Bridge to ReactPHP's await mechanism without full Adapter dependency
        $deferred = new \React\Promise\Deferred();
        
        $this->onResolve(
            fn($v) => $deferred->resolve($v),
            fn($e) => $deferred->reject($e)
        );
        
        return \React\Async\await($deferred->promise());
    }

    /**
     * Sets the successful result and triggers listeners.
     *
     * @internal This method should only be called by the Deferred owner.
     *
     * @param  mixed  $value  The value to complete the future with
     * @return void
     */
    public function fulfill(mixed $value) : void
    {
        if ($this->isResolved || $this->isRejected) return;
        $this->result = $value;
        $this->isResolved = true;
        $this->fire();
    }

    /**
     * Sets the failure reason and triggers listeners.
     *
     * @internal This method should only be called by the Deferred owner.
     *
     * @param  mixed  $reason  The reason for failure (usually Throwable)
     * @return void
     */
    public function notifyFailure(mixed $reason) : void
    {
        if ($this->isResolved || $this->isRejected) return;
        $this->result = $reason;
        $this->isRejected = true;
        $this->fire();
    }

    /**
     * Triggers all attached listeners and clears the list.
     *
     * @return void
     */
    private function fire() : void
    {
        foreach ($this->listeners as $listener) {
            $listener();
        }
        $this->listeners = [];
    }

    /**
     * Checks if the future has completed (success or failure).
     *
     * @return bool
     */
    public function isReady() : bool
    {
        return $this->isResolved || $this->isRejected;
    }

    /**
     * Returns the result if ready, or null if pending (or void/null result).
     *
     * @return mixed
     */
    public function getResult() : mixed
    {
        return $this->result;
    }

    /**
     * Checks if the future was rejected.
     *
     * @return bool
     */
    public function isFailed() : bool
    {
        return $this->isRejected;
    }
}
