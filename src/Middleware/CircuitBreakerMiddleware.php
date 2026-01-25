<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\CacheOptions;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Middleware that prevents cascading failures by stopping requests to failing services
 */
class CircuitBreakerMiddleware implements MiddlewareInterface
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private CacheInterface $storage,
        private int $failureThreshold = 5,
        private int $retryTimeout = 60,
        private string $prefix = 'cb:',
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
    }

    public function handle(string $key, callable $promise_factory, CacheOptions $options, callable $next): PromiseInterface
    {
        $stateKey = $this->prefix . $key . ':state';
        $failureKey = $this->prefix . $key . ':failures';

        $state = $this->storage->get($stateKey, self::STATE_CLOSED);

        if ($state === self::STATE_OPEN) {
            $lastFailureTime = (int) $this->storage->get($this->prefix . $key . ':last_failure', 0);

            if (time() - $lastFailureTime < $this->retryTimeout) {
                $this->logger->error('AsyncCache CIRCUIT_BREAKER: Open state, blocking request', ['key' => $key]);
                return Create::rejectionFor(new \RuntimeException("Circuit Breaker is OPEN for key: $key"));
            }

            // Timeout passed, move to half-open
            $state = self::STATE_HALF_OPEN;
            $this->storage->set($stateKey, self::STATE_HALF_OPEN);
            $this->logger->warning('AsyncCache CIRCUIT_BREAKER: Half-open state, attempting probe request', ['key' => $key]);
        }

        return $next($key, $promise_factory, $options)->then(
            function ($data) use ($stateKey, $failureKey, $key) {
                $this->onSuccess($stateKey, $failureKey, $key);
                return $data;
            },
            function ($reason) use ($stateKey, $failureKey, $key) {
                $this->onFailure($stateKey, $failureKey, $key);
                throw $reason;
            }
        );
    }

    private function onSuccess(string $stateKey, string $failureKey, string $key): void
    {
        $this->storage->set($stateKey, self::STATE_CLOSED);
        $this->storage->set($failureKey, 0);
        $this->logger->info('AsyncCache CIRCUIT_BREAKER: Success, circuit closed', ['key' => $key]);
    }

    private function onFailure(string $stateKey, string $failureKey, string $key): void
    {
        $failures = (int) $this->storage->get($failureKey, 0) + 1;
        $this->storage->set($failureKey, $failures);

        if ($failures >= $this->failureThreshold) {
            $this->storage->set($stateKey, self::STATE_OPEN);
            $this->storage->set($this->prefix . $key . ':last_failure', time());
            $this->logger->critical('AsyncCache CIRCUIT_BREAKER: Failure threshold reached, opening circuit', [
                'key' => $key,
                'failures' => $failures
            ]);
        }
    }
}
