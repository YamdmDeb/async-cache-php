<?php

/*
 *
 *     _                          ____           _            ____  _   _ ____
 *    / \   ___ _   _ _ __   ___ / ___|__ _  ___| |__   ___  |  _ \| | | |  _ \
 *   / _ \ / __| | | | '_ \ / __| |   / _` |/ __| '_ \ / _ \ | |_) | |_| | |_) |
 *  / ___ \\__ \ |_| | | | | (__| |__| (_| | (__| | | |  __/ |  __/|  _  |  __/
 * /_/   \_\___/\__, |_| |_|\___|\____\__,_|\___|_| |_|\___| |_|   |_| |_|_|
 *              |___/
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author Serhii Cherneha
 * @link https://chernega.eu.org/
 *
 *
 */

namespace Fyennyi\AsyncCache\Middleware;

use Fyennyi\AsyncCache\Core\CacheContext;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
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

    private LoggerInterface $logger;

    /**
     * @param  CacheInterface        $storage            Storage for breaker state and failure counts
     * @param  int                   $failure_threshold  Number of failures before opening the circuit
     * @param  int                   $retry_timeout      Timeout in seconds before moving to half-open state
     * @param  string                $prefix             Cache key prefix for breaker state
     * @param  LoggerInterface|null  $logger             Logger for state changes
     */
    public function __construct(
        private CacheInterface $storage,
        private int $failure_threshold = 5,
        private int $retry_timeout = 60,
        private string $prefix = 'cb:',
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Monitors service health and prevents requests during failure states
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  Future result or immediate rejection
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        $state_key = $this->prefix . $context->key . ':state';
        $failure_key = $this->prefix . $context->key . ':failures';

        $state = $this->storage->get($state_key, self::STATE_CLOSED);

        if ($state === self::STATE_OPEN) {
            $val = $this->storage->get($this->prefix . $context->key . ':last_failure', 0);
            $last_failure_time = is_numeric($val) ? (int) $val : 0;

            if (time() - $last_failure_time < $this->retry_timeout) {
                $this->logger->error('AsyncCache CIRCUIT_BREAKER: Open state, blocking request', ['key' => $context->key]);

                $deferred = new Deferred();
                $deferred->reject(new \RuntimeException("Circuit Breaker is OPEN for key: {$context->key}"));
                return $deferred->future();
            }

            // Timeout passed, move to half-open
            $state = self::STATE_HALF_OPEN;
            $this->storage->set($state_key, self::STATE_HALF_OPEN);
            $this->logger->warning('AsyncCache CIRCUIT_BREAKER: Half-open state, attempting probe request', ['key' => $context->key]);
        }

        $deferred = new Deferred();

        /** @var Future $future */
        $future = $next($context);
        $future->onResolve(
            function ($data) use ($state_key, $failure_key, $context, $deferred) {
                $this->onSuccess($state_key, $failure_key, $context->key);
                $deferred->resolve($data);
            },
            function ($reason) use ($state_key, $failure_key, $context, $deferred) {
                $this->onFailure($state_key, $failure_key, $context->key);
                $deferred->reject($reason);
            }
        );

        return $deferred->future();
    }

    /**
     * Handles successful request completion
     * 
     * @param  string  $state_key    Storage key for state
     * @param  string  $failure_key  Storage key for failure count
     * @param  string  $key          Resource identifier
     * @return void
     */
    private function onSuccess(string $state_key, string $failure_key, string $key) : void
    {
        $this->storage->set($state_key, self::STATE_CLOSED);
        $this->storage->set($failure_key, 0);
        $this->logger->info('AsyncCache CIRCUIT_BREAKER: Success, circuit closed', ['key' => $key]);
    }

    /**
     * Handles request failure
     * 
     * @param  string  $state_key    Storage key for state
     * @param  string  $failure_key  Storage key for failure count
     * @param  string  $key          Resource identifier
     * @return void
     */
    private function onFailure(string $state_key, string $failure_key, string $key) : void
    {
        $val = $this->storage->get($failure_key, 0);
        $failures = (is_numeric($val) ? (int) $val : 0) + 1;
        $this->storage->set($failure_key, $failures);

        if ($failures >= $this->failure_threshold) {
            $this->storage->set($state_key, self::STATE_OPEN);
            $this->storage->set($this->prefix . $key . ':last_failure', time());
            $this->logger->critical('AsyncCache CIRCUIT_BREAKER: Failure threshold reached, opening circuit', [
                'key' => $key,
                'failures' => $failures
            ]);
        }
    }
}
