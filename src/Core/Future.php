<?php

namespace Fyennyi\AsyncCache\Core;

use GuzzleHttp\Promise\Promise as GuzzlePromise;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use React\Promise\Deferred as ReactDeferred;
use React\Promise\PromiseInterface as ReactPromiseInterface;

/**
 * The internal "currency" of the library representing a future value
 */
class Future
{
    /** @var array<callable> */
    private array $handlers = [];
    private mixed $result = null;
    private bool $resolved = false;
    private bool $rejected = false;

    /**
     * @param callable|null $waitFn Optional function to drive resolution when wait() is called
     */
    public function __construct(private $waitFn = null) {}

    /**
     * Attaches callbacks for resolution or rejection
     *
     * @param  callable|null  $onFulfilled  Success handler
     * @param  callable|null  $onRejected   Failure handler
     * @return self                         New future for chaining
     */
    public function then(callable $onFulfilled = null, callable $onRejected = null) : self
    {
        $next = new self($this->waitFn);

        $handler = function () use ($next, $onFulfilled, $onRejected) {
            try {
                if ($this->rejected) {
                    if ($onRejected) {
                        $res = $onRejected($this->result);
                        $next->resolve($res);
                    } else {
                        $next->reject($this->result);
                    }
                } else {
                    if ($onFulfilled) {
                        $res = $onFulfilled($this->result);
                        $next->resolve($res);
                    } else {
                        $next->resolve($this->result);
                    }
                }
            } catch (\Throwable $e) {
                $next->reject($e);
            }
        };

        if ($this->resolved || $this->rejected) {
            $handler();
        } else {
            $this->handlers[] = $handler;
        }

        return $next;
    }

    /**
     * Successfully resolves the future with a value
     *
     * @param  mixed  $value  The resolution value
     * @return void
     */
    public function resolve(mixed $value) : void
    {
        if ($this->resolved || $this->rejected) {
            return;
        }
        $this->result = $value;
        $this->resolved = true;
        $this->fire();
    }

    /**
     * Rejects the future with a reason
     *
     * @param  mixed  $reason  The rejection reason (usually Throwable)
     * @return void
     */
    public function reject(mixed $reason) : void
    {
        if ($this->resolved || $this->rejected) {
            return;
        }
        $this->result = $reason;
        $this->rejected = true;
        $this->fire();
    }

    /**
     * Synchronously waits for the future to resolve
     *
     * @return mixed  The resolved value
     * @throws \Throwable If the future was rejected
     */
    public function wait()
    {
        if (! $this->resolved && ! $this->rejected && $this->waitFn) {
            ($this->waitFn)();
        }
        return $this->result;
    }

    /**
     * Converts the future to a Guzzle Promise
     *
     * @return GuzzlePromiseInterface
     */
    public function toGuzzle() : GuzzlePromiseInterface
    {
        $guzzle = new GuzzlePromise(fn() => $this->wait());
        $this->then(
            fn($v) => $guzzle->resolve($v),
            fn($r) => $guzzle->reject($r)
        );
        return $guzzle;
    }

    /**
     * Converts the future to a ReactPHP Promise
     *
     * @return ReactPromiseInterface
     */
    public function toReact() : ReactPromiseInterface
    {
        $deferred = new ReactDeferred();
        $this->then(
            fn($v) => $deferred->resolve($v),
            fn($r) => $deferred->reject($r)
        );
        return $deferred->promise();
    }

    /**
     * Triggers all attached handlers
     */
    private function fire() : void
    {
        foreach ($this->handlers as $handler) {
            $handler();
        }
        $this->handlers = [];
    }
}
