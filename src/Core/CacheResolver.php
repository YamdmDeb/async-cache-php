<?php

namespace Fyennyi\AsyncCache\Core;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Enum\CacheStrategy;
use Fyennyi\AsyncCache\Event\CacheHitEvent;
use Fyennyi\AsyncCache\Event\CacheMissEvent;
use Fyennyi\AsyncCache\Event\RateLimitExceededEvent;
use Fyennyi\AsyncCache\Exception\RateLimitException;
use Fyennyi\AsyncCache\Lock\LockInterface;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Core logic for resolving cache requests
 */
class CacheResolver
{
    /** @var array<string, PromiseInterface> */
    private array $pending_promises = [];

    public function __construct(
        private CacheStorage $storage,
        private RateLimiterInterface $rate_limiter,
        private LockInterface $lock_provider,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    /**
     * The final destination of the pipeline
     */
    public function resolve(string $key, callable $promise_factory, CacheOptions $options): PromiseInterface
    {
        if ($options->strategy === CacheStrategy::ForceRefresh) {
            $this->logger->info('AsyncCache BYPASS: force refresh requested', ['key' => $key, 'status' => CacheStatus::Bypass->value]);
            $this->dispatcher?->dispatch(new CacheMissEvent($key));
            return $this->fetch($key, $promise_factory, $options);
        }

        $cached_item = $this->storage->get($key, $options);

        if ($cached_item instanceof CachedItem) {
            $is_fresh = $cached_item->isFresh();

            if ($is_fresh && $options->x_fetch_beta > 0 && $cached_item->generationTime > 0) {
                $rand = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
                $check = time() - ($cached_item->generationTime * $options->x_fetch_beta * log($rand));

                if ($check > $cached_item->logicalExpireTime) {
                    $this->logger->info('AsyncCache X-FETCH: probabilistic early expiration triggered', [
                        'key' => $key,
                        'status' => CacheStatus::XFetch->value,
                        'ttl_left' => $cached_item->logicalExpireTime - time()
                    ]);
                    $is_fresh = false;
                }
            }

            if ($is_fresh) {
                $this->logger->debug('AsyncCache HIT: fresh data returned', ['key' => $key, 'status' => CacheStatus::Hit->value]);
                $this->dispatcher?->dispatch(new CacheHitEvent($key, $cached_item->data));
                return Create::promiseFor($cached_item->data);
            }

            if ($options->strategy === CacheStrategy::Background) {
                $this->logger->info('AsyncCache STALE: triggering background refresh', ['key' => $key, 'status' => CacheStatus::Stale->value]);
                $this->dispatcher?->dispatch(new CacheHitEvent($key, $cached_item->data)); // Still a hit, but stale
                $this->fetch($key, $promise_factory, $options, $cached_item);
                return Create::promiseFor($cached_item->data);
            }
        }

        $this->dispatcher?->dispatch(new CacheMissEvent($key));
        return $this->fetch($key, $promise_factory, $options, $cached_item);
    }

    /**
     * Internal fetch with locking and coalescing
     */
    private function fetch(string $key, callable $promise_factory, CacheOptions $options, ?CachedItem $stale_item = null): PromiseInterface
    {
        if (isset($this->pending_promises[$key])) {
            $this->logger->info('AsyncCache COALESCE: reusing pending promise', ['key' => $key]);
            return $this->pending_promises[$key];
        }

        $lock_key = 'lock:' . $key;
        if (!$this->lock_provider->acquire($lock_key, 30.0, false)) {
            if ($stale_item !== null) {
                $this->logger->info('AsyncCache LOCK_BUSY: serving stale data', ['key' => $key, 'status' => CacheStatus::Stale->value]);
                $this->dispatcher?->dispatch(new CacheHitEvent($key, $stale_item->data));
                return Create::promiseFor($stale_item->data);
            }
            if (!$this->lock_provider->acquire($lock_key, 30.0, true)) {
                return Create::rejectionFor(new \RuntimeException("Could not acquire lock for key: $key"));
            }
        }

        if ($options->rate_limit_key && $this->rate_limiter->isLimited($options->rate_limit_key)) {
            $this->lock_provider->release($lock_key);
            $this->dispatcher?->dispatch(new RateLimitExceededEvent($key, $options->rate_limit_key));

            if ($options->serve_stale_if_limited && $stale_item !== null) {
                $this->logger->warning('AsyncCache RATE_LIMIT: serving stale data', ['key' => $key, 'status' => CacheStatus::RateLimited->value]);
                return Create::promiseFor($stale_item->data);
            }
            return Create::rejectionFor(new RateLimitException($options->rate_limit_key));
        }

        $this->logger->info('AsyncCache MISS: fetching fresh data', ['key' => $key, 'status' => CacheStatus::Miss->value]);

        if ($options->rate_limit_key) {
            $this->rate_limiter->recordExecution($options->rate_limit_key);
        }

        $start_time = microtime(true);
        $promise = $promise_factory()->then(
            function ($data) use ($key, $options, $lock_key, $start_time) {
                $generation_time = microtime(true) - $start_time;
                unset($this->pending_promises[$key]);
                $this->lock_provider->release($lock_key);
                $this->storage->set($key, $data, $options, $generation_time);
                return $data;
            },
            function ($reason) use ($key, $lock_key) {
                unset($this->pending_promises[$key]);
                $this->lock_provider->release($lock_key);
                $this->logger->error('AsyncCache FETCH_ERROR: failed to fetch fresh data', [
                    'key' => $key,
                    'reason' => $reason
                ]);
                throw $reason;
            }
        );

        return $this->pending_promises[$key] = $promise;
    }
}
