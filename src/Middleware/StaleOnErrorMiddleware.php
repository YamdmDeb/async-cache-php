<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * High-availability middleware that catches exceptions and serves stale data
 */
class StaleOnErrorMiddleware implements MiddlewareInterface
{
    /**
     * @param  LoggerInterface|null           $logger      Logger for reporting failures
     * @param  EventDispatcherInterface|null  $dispatcher  Dispatcher for telemetry events
     */
    public function __construct(
        private ?LoggerInterface $logger = null,
        private ?EventDispatcherInterface $dispatcher = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
    }

    /**
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  Future resolving to fresh or stale data
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        return $next($context)->then(
            fn($data) => $data,
            function ($reason) use ($context) {
                if ($context->staleItem !== null) {
                    $this->logger->warning('AsyncCache STALE_ON_ERROR: fetch failed, serving stale data', [
                        'key' => $context->key,
                        'reason' => $reason instanceof \Throwable ? $reason->getMessage() : (string)$reason
                    ]);
                    
                    $this->dispatcher?->dispatch(new CacheStatusEvent(
                        $context->key, 
                        CacheStatus::Stale, 
                        microtime(true) - $context->startTime, 
                        $context->options->tags
                    ));
                    
                    return $context->staleItem->data;
                }

                throw $reason instanceof \Throwable ? $reason : new \RuntimeException((string)$reason);
            }
        );
    }
}
