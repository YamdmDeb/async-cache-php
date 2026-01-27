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

namespace Fyennyi\AsyncCache\Core;

/**
 * Passive value placeholder representing a result that will eventually be available
 */
class Future
{
    /** @var array<callable> List of callbacks waiting for resolution */
    private array $listeners = [];

    /** @var mixed The resolved value or rejection reason */
    private mixed $result = null;

    /** @var bool Whether the future has been successfully resolved */
    private bool $is_resolved = false;

    /** @var bool Whether the future has been rejected */
    private bool $is_rejected = false;

    /**
     * Internal constructor. Only Deferred should create instances
     */
    public function __construct() {}

    /**
     * Attaches a listener to be called when the value is ready without returning a new Future
     *
     * @param  callable|null  $on_fulfilled  Success handler receiving the result
     * @param  callable|null  $on_rejected   Failure handler receiving the error
     * @return self                          Returns itself for fluent interface (but not chaining new futures)
     */
    public function onResolve(?callable $on_fulfilled = null, ?callable $on_rejected = null) : self
    {
        if ($this->is_resolved) {
            if ($on_fulfilled) $on_fulfilled($this->result);
        } elseif ($this->is_rejected) {
            if ($on_rejected) $on_rejected($this->result);
        } else {
            $this->listeners[] = function() use ($on_fulfilled, $on_rejected) {
                if ($this->is_rejected) {
                    if ($on_rejected) $on_rejected($this->result);
                } else {
                    if ($on_fulfilled) $on_fulfilled($this->result);
                }
            };
        }
        return $this;
    }

    /**
     * Synchronously waits for the value to arrive using ReactPHP await mechanism
     *
     * @return mixed The resolved value
     *
     * @throws \Throwable If the operation failed
     */
    public function wait() : mixed
    {
        if ($this->is_resolved) {
            return $this->result;
        }

        if ($this->is_rejected) {
            $reason = $this->result;
            if ($reason instanceof \Throwable) {
                throw $reason;
            }
            // Use get_debug_type if available or a simple fallback
            $type = \function_exists('get_debug_type') ? get_debug_type($reason) : \gettype($reason);
            $message = \is_scalar($reason) || $reason instanceof \Stringable ? (string)$reason : 'Unknown error (' . $type . ')';
            throw new \RuntimeException($message);
        }

        // Bridge to ReactPHP's await mechanism without full Adapter dependency
        $deferred = new \React\Promise\Deferred();

        $this->onResolve(
            fn($v) => $deferred->resolve($v),
            fn($e) => $deferred->reject($e instanceof \Throwable ? $e : new \RuntimeException(\is_scalar($e) || $e instanceof \Stringable ? (string)$e : 'Unknown error'))
        );

        return \React\Async\await($deferred->promise());
    }

    /**
     * Sets the successful result and triggers listeners
     *
     * @internal This method should only be called by the Deferred owner
     *
     * @param  mixed  $value  The value to complete the future with
     * @return void
     */
    public function fulfill(mixed $value) : void
    {
        if ($this->is_resolved || $this->is_rejected) return;
        $this->result = $value;
        $this->is_resolved = true;
        $this->fire();
    }

    /**
     * Sets the failure reason and triggers listeners
     *
     * @internal This method should only be called by the Deferred owner
     *
     * @param  mixed  $reason  The reason for failure (usually Throwable)
     * @return void
     */
    public function notifyFailure(mixed $reason) : void
    {
        if ($this->is_resolved || $this->is_rejected) return;
        $this->result = $reason;
        $this->is_rejected = true;
        $this->fire();
    }

    /**
     * Triggers all attached listeners and clears the list
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
     * Checks if the future has completed (success or failure)
     *
     * @return bool
     */
    public function isReady() : bool
    {
        return $this->is_resolved || $this->is_rejected;
    }

    /**
     * Returns the result if ready, or null if pending (or void/null result)
     *
     * @return mixed
     */
    public function getResult() : mixed
    {
        return $this->result;
    }

    /**
     * Checks if the future was rejected
     *
     * @return bool
     */
    public function isFailed() : bool
    {
        return $this->is_rejected;
    }
}
