<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Future;

/**
 * Implementation of the Singleflight (Request Coalescing) pattern.
 * Uses the passive Future container to share results among concurrent requests.
 */
class CoalesceMiddleware implements MiddlewareInterface
{
    /** @var array<string, Future> Tracks currently in-flight futures by key */
    private static array $inFlight = [];

    /**
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  The (possibly shared) result future
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        $key = $context->key;

        if (isset(self::$inFlight[$key])) {
            return self::$inFlight[$key];
        }

        $future = $next($context);
        self::$inFlight[$key] = $future;

        // Clean up when the operation completes (success or failure)
        $future->onResolve(
            function () use ($key) {
                unset(self::$inFlight[$key]);
            },
            function () use ($key) {
                unset(self::$inFlight[$key]);
            }
        );

        return $future;
    }
}