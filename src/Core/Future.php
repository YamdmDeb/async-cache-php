<?php

namespace Fyennyi\AsyncCache\Core;

/**
 * The internal "currency" of the library representing a future value.
 * Standard-agnostic and focused on core caching logic.
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
    public function __construct(private $waitFn = null)
    {
    }

    public function then(callable $onFulfilled = null, callable $onRejected = null): self
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

    public function resolve(mixed $value): void
    {
        if ($this->resolved || $this->rejected) return;
        $this->result = $value;
        $this->resolved = true;
        $this->fire();
    }

    public function reject(mixed $reason): void
    {
        if ($this->resolved || $this->rejected) return;
        $this->result = $reason;
        $this->rejected = true;
        $this->fire();
    }

    public function wait()
    {
        if (!$this->resolved && !$this->rejected && $this->waitFn) {
            ($this->waitFn)();
        }
        if ($this->rejected && $this->result instanceof \Throwable) {
            throw $this->result;
        }
        return $this->result;
    }

    public function isSettled(): bool { return $this->resolved || $this->rejected; }

    private function fire(): void
    {
        foreach ($this->handlers as $handler) {
            $handler();
        }
        $this->handlers = [];
    }
}
