<?php

namespace Fyennyi\AsyncCache\Core;

use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;

/**
 * Orchestrates the execution of middleware stack using native Futures.
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
     * Send the context through the pipeline
     */
    public function send(CacheContext $context, callable $destination): Future
    {
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            function ($next, MiddlewareInterface $middleware) {
                return function (CacheContext $context) use ($next, $middleware) {
                    return $middleware->handle($context, $next);
                };
            },
            function (CacheContext $context) use ($destination) {
                return $destination($context);
            }
        );

        return $pipeline($context);
    }
}