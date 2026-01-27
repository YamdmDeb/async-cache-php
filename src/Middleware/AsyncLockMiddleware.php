<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Core\Timer;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheHitEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Synchronization middleware that prevents race conditions using Symfony Lock
 */
class AsyncLockMiddleware implements MiddlewareInterface
{
    /** @var array<string, LockInterface> Active locks storage */
    private array $activeLocks = [];

    /**
     * @param  LockFactory                    $lock_factory  Symfony Lock Factory
     * @param  CacheStorage                   $storage       The cache interaction layer
     * @param  LoggerInterface                $logger        Logging implementation
     * @param  EventDispatcherInterface|null  $dispatcher    Event dispatcher for telemetry
     */
    public function __construct(
        private LockFactory $lock_factory,
        private CacheStorage $storage,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    /**
     * Orchestrates non-blocking lock acquisition and cache population
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  Future resolving to fresh or stale data
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        $lock_key = 'lock:' . $context->key;
        $lock = $this->lock_factory->createLock($lock_key, 30.0);

        if ($lock->acquire(false)) {
            $this->activeLocks[$lock_key] = $lock;
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

        // Create a master deferred that will eventually hold the result of the successful attempt
        $masterDeferred = new Deferred();

        $attempt = function () use (&$attempt, $context, $next, $lock_key, $startTime, $timeout, $masterDeferred) {
            $lock = $this->lock_factory->createLock($lock_key, 30.0);
            
            if ($lock->acquire(false)) {
                $this->activeLocks[$lock_key] = $lock;
                $cached_item = $this->storage->get($context->key, $context->options);
                if ($cached_item instanceof CachedItem && $cached_item->isFresh()) {
                    $this->dispatcher?->dispatch(new CacheStatusEvent($context->key, CacheStatus::Hit, microtime(true) - $startTime, $context->options->tags));
                    $this->releaseLock($lock_key);
                    $masterDeferred->resolve($cached_item->data);
                    return;
                }

                // If acquired but no fresh cache, fetch via next middleware
                $this->handleWithLock($context, $next, $lock_key)->onResolve(
                    fn($v) => $masterDeferred->resolve($v),
                    fn($e) => $masterDeferred->reject($e)
                );
                return;
            }

            if (microtime(true) - $startTime >= $timeout) {
                $masterDeferred->reject(new \RuntimeException("Could not acquire lock for key: {$context->key} (Timeout)"));
                return;
            }

            // Retry after delay
            Timer::delay(0.05)->onResolve(function() use ($attempt) {
                $attempt();
            });
        };

        $attempt();

        return $masterDeferred->future();
    }

    /**
     * Executes next middleware and ensures lock release
     * 
     * @param  CacheContext  $context   The resolution state
     * @param  callable      $next      Next handler in the chain
     * @param  string        $lock_key  Key of the acquired lock
     * @return Future                   Result future
     */
    private function handleWithLock(CacheContext $context, callable $next, string $lock_key) : Future
    {
        $deferred = new Deferred();

        $next($context)->onResolve(
            function ($data) use ($lock_key, $deferred) {
                $this->releaseLock($lock_key);
                $deferred->resolve($data);
            },
            function ($reason) use ($lock_key, $deferred) {
                $this->releaseLock($lock_key);
                $deferred->reject($reason);
            }
        );

        return $deferred->future();
    }

    /**
     * Safely releases and removes the lock from tracking
     *
     * @param  string  $lock_key  Unique identifier of the lock to release
     * @return void
     */
    private function releaseLock(string $lock_key) : void
    {
        if (isset($this->activeLocks[$lock_key])) {
            $this->activeLocks[$lock_key]->release();
            unset($this->activeLocks[$lock_key]);
        }
    }
}
