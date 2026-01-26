<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Bridge\PromiseBridge;
use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Event\CacheMissEvent;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;

/**
 * The final middleware that actually calls the source and populates the cache
 */
class SourceFetchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CacheStorage $storage,
        private LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    public function handle(CacheContext $context, callable $next): PromiseInterface
    {
        $this->dispatcher?->dispatch(new CacheMissEvent($context->key));
        
        $fetchStartTime = microtime(true);
        $promise = PromiseBridge::toReact(($context->promiseFactory)());

        return $promise->then(
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
