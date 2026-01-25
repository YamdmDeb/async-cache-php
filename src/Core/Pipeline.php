<?php

namespace Fyennyi\AsyncCache\Core;

use Fyennyi\AsyncCache\CacheOptions;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Orchestrates the execution of middleware stack
 */
class Pipeline
{
    /**
     * @param array $middlewares Array of MiddlewareInterface objects
     */
    public function __construct(
        private array $middlewares = []
    ) {
    }

    /**
     * Sends the request through the pipeline
     */
    public function send(string $key, callable $promise_factory, CacheOptions $options, callable $destination): PromiseInterface
    {
        $pipeline = array_reverse($this->middlewares);

        $next = function (string $k, callable $f, CacheOptions $o) use ($destination) {
            return $destination($k, $f, $o);
        };

        foreach ($pipeline as $middleware) {
            $currentNext = $next;
            $next = function (string $k, callable $f, CacheOptions $o) use ($middleware, $currentNext) {
                return $middleware->handle($k, $f, $o, $currentNext);
            };
        }

        return $next($key, $promise_factory, $options);
    }
}
