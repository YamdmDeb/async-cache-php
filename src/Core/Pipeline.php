<?php

namespace Fyennyi\AsyncCache\Core;

use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;

/**
 * Orchestrates the recursive execution of the middleware stack
 */
class Pipeline
{
    /**
     * @param  MiddlewareInterface[]  $middlewares  Stack of handlers to execute
     */
    public function __construct(
        private array $middlewares = []
    ) {
    }

    /**
     * Sends the context through the pipeline towards the final destination
     *
     * @param  CacheContext  $context      The current state object
     * @param  callable      $destination  The final handler (usually the fetcher)
     * @return Future                      Combined future representing the full pipeline
     */
    public function send(CacheContext $context, callable $destination) : Future
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
