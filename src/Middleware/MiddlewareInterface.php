<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Future;

/**
 * Interface for all AsyncCache middlewares using library-native Futures
 */
interface MiddlewareInterface
{
    /**
     * Handle the cache request
     * 
     * @param  CacheContext $context  The current resolution state
     * @param  callable     $next     The next middleware in the pipeline
     * @return Future
     */
    public function handle(CacheContext $context, callable $next) : Future;
}
