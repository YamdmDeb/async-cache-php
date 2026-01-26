<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheMissEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * The final middleware that calls the source and populates the cache using Futures
 */
class SourceFetchMiddleware implements MiddlewareInterface
{
    /**
     * @param  CacheStorage                   $storage     The cache interaction layer
     * @param  LoggerInterface                $logger      Logging implementation
     * @param  EventDispatcherInterface|null  $dispatcher  Event dispatcher for telemetry
     */
    public function __construct(
        private CacheStorage $storage,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    /**
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain (usually empty destination)
     * @return Future                  Future resolving to freshly fetched data
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        $this->dispatcher?->dispatch(new CacheMissEvent($context->key));

        $fetchStartTime = microtime(true);

        // We call the factory and wrap the result in our native Future
        $sourceResult = ($context->promiseFactory)();

        // Use Deferred to bridge from whatever the factory returns (Guzzle/React/Value) to Future
        $deferred = new Deferred();

        if (method_exists($sourceResult, 'then')) {
            $sourceResult->then(
                fn($v) => $deferred->resolve($v),
                fn($r) => $deferred->reject($r)
            );
        } else {
            $deferred->resolve($sourceResult);
        }

        return $deferred->future()->then(
            function ($data) use ($context, $fetchStartTime) {
                $generationTime = microtime(true) - $fetchStartTime;
                $this->storage->set($context->key, $data, $context->options, $generationTime);

                $this->dispatcher?->dispatch(new CacheStatusEvent(
                    $context->key,
                    CacheStatus::Miss,
                    microtime(true) - $context->startTime,
                    $context->options->tags
                ));

                return $data;
            },
            function ($reason) use ($context) {
                $this->logger->error('AsyncCache FETCH_ERROR: failed to fetch fresh data', [
                    'key' => $context->key,
                    'reason' => $reason
                ]);
                throw $reason instanceof \Throwable ? $reason : new \RuntimeException((string)$reason);
            }
        );
    }
}
