<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheHitEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Lock\AsyncLockWaiter;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Ensures thread-safety for cache refreshing using non-blocking locks
 */
class AsyncLockMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LockInterface $lock_provider,
        private CacheStorage $storage,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    public function handle(CacheContext $context, callable $next): PromiseInterface
    {
        $lock_key = 'lock:' . $context->key;

        // Try immediate acquire
        if ($this->lock_provider->acquire($lock_key, 30.0, false)) {
            return $this->handleWithLock($context, $next, $lock_key);
        }

        // If lock is busy, check if we can serve stale data from context (collected by previous middleware)
        if ($context->staleItem !== null) {
            $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Stale, microtime(true) - $context->startTime, $context->options->tags));
            $this->dispatcher?->dispatch(new CacheHitEvent($context->key, $context->staleItem->data));
            return Create::promiseFor($context->staleItem->data);
        }

        // Wait asynchronously
        return AsyncLockWaiter::waitFor($this->lock_provider, $lock_key, 30.0)
            ->then(function (bool $acquired) use ($context, $next, $lock_key) {
                if (!$acquired) {
                    throw new \RuntimeException("Could not acquire lock for key: {$context->key} (Timeout)");
                }

                // Double-Check after lock acquisition
                $cached_item = $this->storage->get($context->key, $context->options);
                if ($cached_item instanceof CachedItem && $cached_item->isFresh()) {
                    $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Hit, microtime(true) - $context->startTime, $context->options->tags));
                    $this->lock_provider->release($lock_key);
                    return $cached_item->data;
                }

                return $this->handleWithLock($context, $next, $lock_key);
            });
    }

    private function handleWithLock(CacheContext $context, callable $next, string $lock_key): PromiseInterface
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
