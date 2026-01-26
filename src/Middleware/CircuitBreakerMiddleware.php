<?php

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
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

    /**
     * @param  CacheInterface       $storage           Storage for breaker state and failure counts
     * @param  int                  $failureThreshold  Number of failures before opening the circuit
     * @param  int                  $retryTimeout      Timeout in seconds before moving to half-open state
     * @param  string               $prefix            Cache key prefix for breaker state
     * @param  LoggerInterface|null $logger            Logger for state changes
     */
    public function __construct(
        private CacheInterface $storage,
        private int $failureThreshold = 5,
        private int $retryTimeout = 60,
        private string $prefix = 'cb:',
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $this->logger ?? new NullLogger();
    }

    /**
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return PromiseInterface        Future result or immediate rejection
     */
    public function handle(CacheContext $context, callable $next) : PromiseInterface
    {
        $stateKey = $this->prefix . $context->key . ':state';
        $failureKey = $this->prefix . $context->key . ':failures';

        $state = $this->storage->get($stateKey, self::STATE_CLOSED);

        if ($state === self::STATE_OPEN) {
            $lastFailureTime = (int) $this->storage->get($this->prefix . $context->key . ':last_failure', 0);

            if (time() - $lastFailureTime < $this->retryTimeout) {
                $this->logger->error('AsyncCache CIRCUIT_BREAKER: Open state, blocking request', ['key' => $context->key]);
                return Create::rejectionFor(new \RuntimeException("Circuit Breaker is OPEN for key: {$context->key}"));
            }

            // Timeout passed, move to half-open
            $state = self::STATE_HALF_OPEN;
            $this->storage->set($stateKey, self::STATE_HALF_OPEN);
            $this->logger->warning('AsyncCache CIRCUIT_BREAKER: Half-open state, attempting probe request', ['key' => $context->key]);
        }

        return $next($context)->then(
            function ($data) use ($stateKey, $failureKey, $context) {
                $this->onSuccess($stateKey, $failureKey, $context->key);
                return $data;
            },
            function ($reason) use ($stateKey, $failureKey, $context) {
                $this->onFailure($stateKey, $failureKey, $context->key);
                throw $reason;
            }
        );
    }

    /**
     * Handles successful request completion
     *
     * @param  string  $stateKey    Storage key for state
     * @param  string  $failureKey  Storage key for failure count
     * @param  string  $key         Resource identifier
     * @return void
     */
    private function onSuccess(string $stateKey, string $failureKey, string $key) : void
    {
        $this->storage->set($stateKey, self::STATE_CLOSED);
        $this->storage->set($failureKey, 0);
        $this->logger->info('AsyncCache CIRCUIT_BREAKER: Success, circuit closed', ['key' => $key]);
    }

    /**
     * Handles request failure
     *
     * @param  string  $stateKey    Storage key for state
     * @param  string  $failureKey  Storage key for failure count
     * @param  string  $key         Resource identifier
     * @return void
     */
    private function onFailure(string $stateKey, string $failureKey, string $key) : void
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
