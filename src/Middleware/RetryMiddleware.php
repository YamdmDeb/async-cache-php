<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use Fyennyi\AsyncCache\Core\Timer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Middleware that retries failed requests with exponential backoff
 */
class RetryMiddleware implements MiddlewareInterface
{
    /**
     * @param  int                   $maxRetries      Maximum number of retry attempts
     * @param  int                   $initialDelayMs  Delay before the first retry in milliseconds
     * @param  float                 $multiplier      Multiplier for exponential backoff
     * @param  LoggerInterface|null  $logger          Logger for reporting retries
     */
    public function __construct(
        private int $maxRetries = 3,
        private int $initialDelayMs = 100,
        private float $multiplier = 2.0,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
    }

    /**
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  Future result
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        return $this->attempt($context, $next, 0);
    }

    /**
     * Recursively attempt the request
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @param  int           $retries  Current retry attempt counter
     * @return Future                  Result of the attempt
     */
    private function attempt(CacheContext $context, callable $next, int $retries) : Future
    {
        $deferred = new Deferred();

        $next($context)->onResolve(
            function ($value) use ($deferred) {
                $deferred->resolve($value);
            },
            function ($reason) use ($context, $next, $retries, $deferred) {
                if ($retries >= $this->maxRetries) {
                    $this->logger->error('AsyncCache RETRY: Max retries reached', [
                        'key' => $context->key,
                        'retries' => $retries,
                        'reason' => $reason
                    ]);
                    $deferred->reject($reason);
                    return;
                }

                $delayMs = $this->initialDelayMs * pow($this->multiplier, $retries);

                $this->logger->warning('AsyncCache RETRY: Request failed, retrying...', [
                    'key' => $context->key,
                    'attempt' => $retries + 1,
                    'delay_ms' => $delayMs,
                    'reason' => $reason
                ]);

                // Non-blocking wait
                Timer::delay($delayMs / 1000)->onResolve(function () use ($context, $next, $retries, $deferred) {
                    $this->attempt($context, $next, $retries + 1)->onResolve(
                        fn($v) => $deferred->resolve($v),
                        fn($e) => $deferred->reject($e)
                    );
                });
            }
        );

        return $deferred->future();
    }
}