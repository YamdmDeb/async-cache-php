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
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * Fluent builder for AsyncCacheManager focused on ReactPHP and Fibers
 */
class AsyncCacheBuilder
{
    private ?LimiterInterface $rateLimiter = null;
    private ?LoggerInterface $logger = null;
    private ?LockFactory $lockFactory = null;
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    private ?EventDispatcherInterface $dispatcher = null;
    private ?SerializerInterface $serializer = null;

    /**
     * @param  CacheInterface  $cacheAdapter  The underlying PSR-16 cache implementation
     */
    public function __construct(private CacheInterface $cacheAdapter)
    {
    }

    /**
     * Entry point for the fluent builder
     *
     * @param  CacheInterface  $cacheAdapter  The underlying cache storage
     * @return self                           New builder instance
     */
    public static function create(CacheInterface $cacheAdapter) : self
    {
        return new self($cacheAdapter);
    }

    /**
     * Sets a custom Symfony Rate Limiter implementation
     *
     * @param  LimiterInterface  $rateLimiter  The implementation to use
     * @return self                            Current builder instance
     */
    public function withRateLimiter(LimiterInterface $rateLimiter) : self
    {
        $this->rateLimiter = $rateLimiter;
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
     * @param  LockFactory  $lockFactory  Lock factory instance
     * @return self                       Current builder instance
     */
    public function withLockFactory(LockFactory $lockFactory) : self
    {
        $this->lockFactory = $lockFactory;
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
            $this->cacheAdapter,
            $this->rateLimiter,
            $this->logger,
            $this->lockFactory,
            $this->middlewares,
            $this->dispatcher,
            $this->serializer
        );
    }
}
