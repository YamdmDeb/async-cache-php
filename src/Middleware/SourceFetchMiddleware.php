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
     * Fetches fresh data from the source and updates cache
     *
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

        // Use Deferred to bridge from whatever the factory returns (Guzzle/React/Value/Future) to our Future
        $sourceDeferred = new Deferred();

        if ($sourceResult instanceof Future) {
            $sourceResult->onResolve(
                fn($v) => $sourceDeferred->resolve($v),
                fn($r) => $sourceDeferred->reject($r)
            );
        } elseif (is_object($sourceResult) && method_exists($sourceResult, 'then')) {
            // Support Guzzle/React promises via duck typing
            $sourceResult->then(
                fn($v) => $sourceDeferred->resolve($v),
                fn($r) => $sourceDeferred->reject($r)
            );
        } else {
            $sourceDeferred->resolve($sourceResult);
        }

        // Create a new deferred for the "after-save" result
        $finalDeferred = new Deferred();

        $sourceDeferred->future()->onResolve(
            function ($data) use ($context, $fetchStartTime, $finalDeferred) {
                try {
                    $generationTime = microtime(true) - $fetchStartTime;
                    $this->storage->set($context->key, $data, $context->options, $generationTime);

                    $this->dispatcher?->dispatch(new CacheStatusEvent(
                        $context->key,
                        CacheStatus::Miss,
                        microtime(true) - $context->startTime,
                        $context->options->tags
                    ));

                    $finalDeferred->resolve($data);
                } catch (\Throwable $e) {
                    // If saving fails, we still might want to return the data, or log error
                    // For now, we propagate the error if storage fails critically
                    $finalDeferred->reject($e);
                }
            },
            function ($reason) use ($context, $finalDeferred) {
                $this->logger->error('AsyncCache FETCH_ERROR: failed to fetch fresh data', [
                    'key' => $context->key,
                    'reason' => $reason
                ]);
                
                $finalDeferred->reject($reason instanceof \Throwable ? $reason : new \RuntimeException((string)$reason));
            }
        );

        return $finalDeferred->future();
    }
}
