<?php

namespace Fyennyi\AsyncCache\Runtime;

use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use function React\Promise\Timer\resolve as delay;

/**
 * Runtime driver for ReactPHP environment
 */
class ReactRuntime implements RuntimeInterface
{
    public function delay(float $seconds): PromiseInterface
    {
        return delay($seconds);
    }

    public function resolve(mixed $value): PromiseInterface
    {
        return resolve($value);
    }

    public static function isSupported(): bool
    {
        return interface_exists(PromiseInterface::class) && function_exists('React\Promise\Timer\resolve');
    }
}
