<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Middleware that retries failed requests with exponential backoff
 */
class RetryMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int $maxRetries = 3,
        private int $initialDelayMs = 100,
        private float $multiplier = 2.0,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
    }

    public function handle(string $key, callable $promise_factory, CacheOptions $options, callable $next): PromiseInterface
    {
        return $this->attempt($key, $promise_factory, $options, $next, 0);
    }

    /**
     * Recursively attempt the request
     */
    private function attempt(string $key, callable $promise_factory, CacheOptions $options, callable $next, int $retries): PromiseInterface
    {
        return $next($key, $promise_factory, $options)->otherwise(
            function ($reason) use ($key, $promise_factory, $options, $next, $retries) {
                if ($retries >= $this->maxRetries) {
                    $this->logger->error('AsyncCache RETRY: Max retries reached', [
                        'key' => $key,
                        'retries' => $retries,
                        'reason' => $reason
                    ]);
                    throw $reason;
                }

                $delay = $this->initialDelayMs * pow($this->multiplier, $retries);

                $this->logger->warning('AsyncCache RETRY: Request failed, retrying...', [
                    'key' => $key,
                    'attempt' => $retries + 1,
                    'delay_ms' => $delay,
                    'reason' => $reason
                ]);

                // Wait for the delay
                usleep((int) ($delay * 1000));

                return $this->attempt($key, $promise_factory, $options, $next, $retries + 1);
            }
        );
    }
}
