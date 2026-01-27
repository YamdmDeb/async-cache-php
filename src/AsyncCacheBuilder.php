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

namespace Fyennyi\AsyncCache;

use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;
use Fyennyi\AsyncCache\Serializer\SerializerInterface;
use Fyennyi\AsyncCache\Storage\AsyncCacheAdapterInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface as PsrCacheInterface;
use React\Cache\CacheInterface as ReactCacheInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * Fluent builder for AsyncCacheManager focused on asynchronous operations
 */
class AsyncCacheBuilder
{
    private ?LimiterInterface $rate_limiter = null;
    private ?LoggerInterface $logger = null;
    private ?LockFactory $lock_factory = null;
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    private ?EventDispatcherInterface $dispatcher = null;
    private ?SerializerInterface $serializer = null;

    /**
     * @param  PsrCacheInterface|ReactCacheInterface|AsyncCacheAdapterInterface  $cache_adapter  The cache implementation
     */
    public function __construct(private PsrCacheInterface|ReactCacheInterface|AsyncCacheAdapterInterface $cache_adapter)
    {
    }

    /**
     * Entry point for the fluent builder
     *
     * @param  PsrCacheInterface|ReactCacheInterface|AsyncCacheAdapterInterface  $cache_adapter  The underlying cache storage
     * @return self                                                                              New builder instance
     */
    public static function create(PsrCacheInterface|ReactCacheInterface|AsyncCacheAdapterInterface $cache_adapter) : self
    {
        return new self($cache_adapter);
    }

    /**
     * Sets a custom Symfony Rate Limiter implementation
     *
     * @param  LimiterInterface  $rate_limiter  The implementation to use
     * @return self                             Current builder instance
     */
    public function withRateLimiter(LimiterInterface $rate_limiter) : self
    {
        $this->rate_limiter = $rate_limiter;
        return $this;
    }

    /**
     * Sets the PSR-3 logger
     *
     * @param  LoggerInterface  $logger  Logger implementation
     * @return self                      Current builder instance
     */
    public function withLogger(LoggerInterface $logger) : self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Configures the Symfony Lock Factory
     *
     * @param  LockFactory  $lock_factory  Lock factory instance
     * @return self                        Current builder instance
     */
    public function withLockFactory(LockFactory $lock_factory) : self
    {
        $this->lock_factory = $lock_factory;
        return $this;
    }

    /**
     * Appends a custom middleware to the pipeline
     *
     * @param  MiddlewareInterface  $middleware  Middleware implementation
     * @return self                              Current builder instance
     */
    public function withMiddleware(MiddlewareInterface $middleware) : self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Sets the PSR-14 event dispatcher
     *
     * @param  EventDispatcherInterface  $dispatcher  Dispatcher implementation
     * @return self                                    Current builder instance
     */
    public function withEventDispatcher(EventDispatcherInterface $dispatcher) : self
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    /**
     * Sets a custom serializer for data storage
     *
     * @param  SerializerInterface  $serializer  Serializer implementation
     * @return self                              Current builder instance
     */
    public function withSerializer(SerializerInterface $serializer) : self
    {
        $this->serializer = $serializer;
        return $this;
    }

    /**
     * Finalizes the configuration and creates the AsyncCacheManager
     *
     * @return AsyncCacheManager  Fully configured manager instance
     */
    public function build() : AsyncCacheManager
    {
        return new AsyncCacheManager(
            $this->cache_adapter,
            $this->rate_limiter,
            $this->logger,
            $this->lock_factory,
            $this->middlewares,
            $this->dispatcher,
            $this->serializer
        );
    }
}
