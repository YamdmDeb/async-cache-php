<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use GuzzleHttp\Promise\PromiseInterface;
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
     * @return PromiseInterface        Future result
     */
    public function handle(CacheContext $context, callable $next) : PromiseInterface
    {
        return $this->attempt($context, $next, 0);
    }

    /**
     * Recursively attempt the request
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @param  int           $retries  Current retry attempt counter
     * @return PromiseInterface        Result of the attempt
     */
    private function attempt(CacheContext $context, callable $next, int $retries) : PromiseInterface
    {
        return $next($context)->otherwise(
            function ($reason) use ($context, $next, $retries) {
                if ($retries >= $this->maxRetries) {
                    $this->logger->error('AsyncCache RETRY: Max retries reached', [
                        'key' => $context->key,
                        'retries' => $retries,
                        'reason' => $reason
                    ]);
                    throw $reason;
                }

                $delay = $this->initialDelayMs * pow($this->multiplier, $retries);

                $this->logger->warning('AsyncCache RETRY: Request failed, retrying...', [
                    'key' => $context->key,
                    'attempt' => $retries + 1,
                    'delay_ms' => $delay,
                    'reason' => $reason
                ]);

                // Wait for the delay (still blocking, but now using Context)
                usleep((int) ($delay * 1000));

                return $this->attempt($context, $next, $retries + 1);
            }
        );
    }
}
