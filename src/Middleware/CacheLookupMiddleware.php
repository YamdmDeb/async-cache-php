<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Enum\CacheStrategy;
use Fyennyi\AsyncCache\Event\CacheHitEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Scheduler\SchedulerInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles initial cache lookup and freshness check using native Futures
 */
class CacheLookupMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CacheStorage $storage,
        private SchedulerInterface $scheduler,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    public function handle(CacheContext $context, callable $next): Future
    {
        if ($context->options->strategy === CacheStrategy::ForceRefresh) {
            $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Bypass, 0, $context->options->tags));
            return $next($context);
        }

        $cached_item = $this->storage->get($context->key, $context->options);

        if ($cached_item instanceof CachedItem) {
            $context->staleItem = $cached_item;
            $is_fresh = $cached_item->isFresh();

            if ($is_fresh && $context->options->x_fetch_beta > 0 && $cached_item->generationTime > 0) {
                $rand = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
                $check = time() - ($cached_item->generationTime * $context->options->x_fetch_beta * log($rand));

                if ($check > $cached_item->logicalExpireTime) {
                    $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::XFetch, microtime(true) - $context->startTime, $context->options->tags));
                    $is_fresh = false;
                }
            }

            if ($is_fresh) {
                $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Hit, microtime(true) - $context->startTime, $context->options->tags));
                $this->dispatcher?->dispatch(new CacheHitEvent($context->key, $cached_item->data));
                return $this->scheduler->resolve($cached_item->data);
            }

            if ($context->options->strategy === CacheStrategy::Background) {
                $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Stale, microtime(true) - $context->startTime, $context->options->tags));
                $this->dispatcher?->dispatch(new CacheHitEvent($context->key, $cached_item->data));
                $next($context);
                return $this->scheduler->resolve($cached_item->data);
            }
        }

        return $next($context);
    }
}