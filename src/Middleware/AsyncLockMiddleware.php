<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheHitEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Scheduler\SchedulerInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Ensures thread-safety for cache refreshing using native Futures and non-blocking locks
 */
class AsyncLockMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LockInterface $lock_provider,
        private CacheStorage $storage,
        private SchedulerInterface $scheduler,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    public function handle(CacheContext $context, callable $next): Future
    {
        $lock_key = 'lock:' . $context->key;

        // Try immediate acquire
        if ($this->lock_provider->acquire($lock_key, 30.0, false)) {
            return $this->handleWithLock($context, $next, $lock_key);
        }

        // Serve stale if lock busy
        if ($context->staleItem !== null) {
            $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Stale, microtime(true) - $context->startTime, $context->options->tags));
            $this->dispatcher?->dispatch(new CacheHitEvent($context->key, $context->staleItem->data));
            return $this->scheduler->resolve($context->staleItem->data);
        }

        $this->logger->debug('AsyncCache LOCK_BUSY: waiting for lock via scheduler', ['key' => $context->key]);
        
        $startTime = microtime(true);
        $timeout = 10.0;
        
        $attempt = function () use (&$attempt, $context, $next, $lock_key, $startTime, $timeout) {
            if ($this->lock_provider->acquire($lock_key, 30.0, false)) {
                // Double-Check after lock acquisition
                $cached_item = $this->storage->get($context->key, $context->options);
                if ($cached_item instanceof CachedItem && $cached_item->isFresh()) {
                    $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Hit, microtime(true) - $context->startTime, $context->options->tags));
                    $this->lock_provider->release($lock_key);
                    return $this->scheduler->resolve($cached_item->data);
                }
                return $this->handleWithLock($context, $next, $lock_key);
            }

            if (microtime(true) - $startTime >= $timeout) {
                throw new \RuntimeException("Could not acquire lock for key: {$context->key} (Timeout)");
            }

            return $this->scheduler->delay(0.05)->then($attempt);
        };

        return $attempt();
    }

    private function handleWithLock(CacheContext $context, callable $next, string $lock_key): Future
    {
        return $next($context)->then(
            function ($data) use ($lock_key) {
                $this->lock_provider->release($lock_key);
                return $data;
            },
            function ($reason) use ($lock_key) {
                $this->lock_provider->release($lock_key);
                throw $reason;
            }
        );
    }
}
