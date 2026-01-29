<?php

namespace Tests\Unit\Config;

use Fyennyi\AsyncCache\Config\AsyncCacheConfig;
use Fyennyi\AsyncCache\Config\AsyncCacheConfigBuilder;
use Fyennyi\AsyncCache\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\LimiterInterface;

class AsyncCacheConfigBuilderTest extends TestCase
{
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
    }

    public function testWithMiddleware(): void
    {
        $middleware = $this->createMock(MiddlewareInterface::class);
        
        $config = AsyncCacheConfig::builder($this->cache)
            ->withMiddleware($middleware)
            ->build();

        $this->assertInstanceOf(AsyncCacheConfig::class, $config);
        $this->assertSame([$middleware], $config->getMiddlewares());
    }

    public function testWithMultipleMiddlewares(): void
    {
        $middleware1 = $this->createMock(MiddlewareInterface::class);
        $middleware2 = $this->createMock(MiddlewareInterface::class);
        
        $config = AsyncCacheConfig::builder($this->cache)
            ->withMiddleware($middleware1)
            ->withMiddleware($middleware2)
            ->build();

        $this->assertSame([$middleware1, $middleware2], $config->getMiddlewares());
    }

    public function testWithEventDispatcher(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        
        $config = AsyncCacheConfig::builder($this->cache)
            ->withEventDispatcher($dispatcher)
            ->build();

        $this->assertInstanceOf(AsyncCacheConfig::class, $config);
        $this->assertSame($dispatcher, $config->getDispatcher());
    }

    public function testWithRateLimiter(): void
    {
        $rateLimiter = $this->createMock(LimiterInterface::class);
        
        $config = AsyncCacheConfig::builder($this->cache)
            ->withRateLimiter($rateLimiter)
            ->build();

        $this->assertSame($rateLimiter, $config->getRateLimiter());
    }

    public function testWithLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        
        $config = AsyncCacheConfig::builder($this->cache)
            ->withLogger($logger)
            ->build();

        $this->assertSame($logger, $config->getLogger());
    }

    public function testWithLockFactory(): void
    {
        $lockFactory = $this->createMock(LockFactory::class);
        
        $config = AsyncCacheConfig::builder($this->cache)
            ->withLockFactory($lockFactory)
            ->build();

        $this->assertSame($lockFactory, $config->getLockFactory());
    }

    public function testWithClock(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        
        $config = AsyncCacheConfig::builder($this->cache)
            ->withClock($clock)
            ->build();

        $this->assertSame($clock, $config->getClock());
    }

    public function testFluentInterface(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $rateLimiter = $this->createMock(LimiterInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $middleware = $this->createMock(MiddlewareInterface::class);
        
        $config = AsyncCacheConfig::builder($this->cache)
            ->withLogger($logger)
            ->withRateLimiter($rateLimiter)
            ->withEventDispatcher($dispatcher)
            ->withMiddleware($middleware)
            ->build();

        $this->assertInstanceOf(AsyncCacheConfig::class, $config);
        $this->assertSame($logger, $config->getLogger());
        $this->assertSame($rateLimiter, $config->getRateLimiter());
        $this->assertSame($dispatcher, $config->getDispatcher());
        $this->assertSame([$middleware], $config->getMiddlewares());
    }
}
