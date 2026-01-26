<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Core\Timer;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheHitEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Synchronization middleware that prevents race conditions during cache population
 */
class AsyncLockMiddleware implements MiddlewareInterface
{
    /**
     * @param  LockInterface                  $lock_provider  The lock management implementation
     * @param  CacheStorage                   $storage        The cache interaction layer
     * @param  LoggerInterface                $logger         Logging implementation
     * @param  EventDispatcherInterface|null  $dispatcher     Event dispatcher for telemetry
     */
    public function __construct(
        private LockInterface $lock_provider,
        private CacheStorage $storage,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    /**
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  Future resolving to fresh or stale data
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        $lock_key = 'lock:' . $context->key;

        if ($this->lock_provider->acquire($lock_key, 30.0, false)) {
            return $this->handleWithLock($context, $next, $lock_key);
        }

        if ($context->staleItem !== null) {
            $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Stale, microtime(true) - $context->startTime, $context->options->tags));
            $this->dispatcher?->dispatch(new CacheHitEvent($context->key, $context->staleItem->data));
            $deferred = new Deferred();
            $deferred->resolve($context->staleItem->data);
            return $deferred->future();
        }

        $this->logger->debug('AsyncCache LOCK_BUSY: waiting for lock asynchronously', ['key' => $context->key]);

        $startTime = microtime(true);
        $timeout = 10.0;

        $attempt = function () use (&$attempt, $context, $next, $lock_key, $startTime, $timeout) {
            if ($this->lock_provider->acquire($lock_key, 30.0, false)) {
                $cached_item = $this->storage->get($context->key, $context->options);
                if ($cached_item instanceof CachedItem && $cached_item->isFresh()) {
                    $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Hit, microtime(true) - $startTime, $context->options->tags));
                    $this->lock_provider->release($lock_key);
                    $deferred = new Deferred();
                    $deferred->resolve($cached_item->data);
                    return $deferred->future();
                }
                return $this->handleWithLock($context, $next, $lock_key);
            }

            if (microtime(true) - $startTime >= $timeout) {
                throw new \RuntimeException("Could not acquire lock for key: {$context->key} (Timeout)");
            }

            return Timer::delay(0.05)->then($attempt);
        };

        return $attempt();
    }

    /**
     * Helper to execute next middleware and ensure lock release
     *
     * @param  CacheContext  $context   The resolution state
     * @param  callable      $next      Next handler in the chain
     * @param  string        $lock_key  Key of the acquired lock
     * @return Future                   Result future
     */
    private function handleWithLock(CacheContext $context, callable $next, string $lock_key) : Future
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
