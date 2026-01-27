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
use Fyennyi\AsyncCache\Core\Timer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Middleware that retries failed requests with exponential backoff
 */
class RetryMiddleware implements MiddlewareInterface
{
    private LoggerInterface $logger;

    /**
     * @param  int                   $max_retries       Maximum number of retry attempts
     * @param  int                   $initial_delay_ms  Delay before the first retry in milliseconds
     * @param  float                 $multiplier        Multiplier for exponential backoff
     * @param  LoggerInterface|null  $logger            Logger for reporting retries
     */
    public function __construct(
        private int $max_retries = 3,
        private int $initial_delay_ms = 100,
        private float $multiplier = 2.0,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Handles the request with automatic retry logic
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @return Future                  Future result
     */
    public function handle(CacheContext $context, callable $next) : Future
    {
        return $this->attempt($context, $next, 0);
    }

    /**
     * Recursively attempt the request with backoff
     *
     * @param  CacheContext  $context  The resolution state
     * @param  callable      $next     Next handler in the chain
     * @param  int           $retries  Current retry attempt counter
     * @return Future                  Result of the attempt
     */
    private function attempt(CacheContext $context, callable $next, int $retries) : Future
    {
        $deferred = new Deferred();

        /** @var Future $future */
        $future = $next($context);

        $future->onResolve(
            function ($value) use ($deferred) {
                $deferred->resolve($value);
            },
            function ($reason) use ($context, $next, $retries, $deferred) {
                if ($retries >= $this->max_retries) {
                    $this->logger->error('AsyncCache RETRY: Max retries reached', [
                        'key' => $context->key,
                        'retries' => $retries,
                        'reason' => $reason
                    ]);
                    $deferred->reject($reason);
                    return;
                }

                $delay_ms = $this->initial_delay_ms * pow($this->multiplier, $retries);

                $this->logger->warning('AsyncCache RETRY: Request failed, retrying...', [
                    'key' => $context->key,
                    'attempt' => $retries + 1,
                    'delay_ms' => $delay_ms,
                    'reason' => $reason
                ]);

                // Non-blocking wait
                Timer::delay($delay_ms / 1000)->onResolve(function () use ($context, $next, $retries, $deferred) {
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
