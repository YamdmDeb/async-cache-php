<?php

namespace Tests\Unit;

use Fyennyi\AsyncCache\AsyncCacheManager;
use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Exception\RateLimitException;
use Fyennyi\AsyncCache\RateLimiter\RateLimiterInterface;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class AsyncCacheManagerTest extends TestCase
{
    private MockObject|CacheInterface $cache;
    private MockObject|RateLimiterInterface $rateLimiter;
    private AsyncCacheManager $manager;

    protected function setUp() : void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->manager = new AsyncCacheManager($this->cache, $this->rateLimiter);
    }

    public function testReturnsFreshCacheImmediately() : void
    {
        $key = 'test_key';
        $data = 'cached_data';
        $options = new CacheOptions(ttl: 60);

        // Mock cache hit with fresh data
        $this->cache->expects($this->once())
            ->method('get')
            ->with($key)
            ->willReturn([
                'd' => $data,
                'e' => time() + 100 // Expires in future
            ]);

        // Rate limiter should NOT be called
        $this->rateLimiter->expects($this->never())->method('isLimited');

        $promise = $this->manager->wrap($key, fn() => Create::promiseFor('new_data'), $options);
        $result = $promise->wait();

        $this->assertSame($data, $result);
    }

    public function testFetchesNewDataOnCacheMiss() : void
    {
        $key = 'test_key';
        $newData = 'new_data';
        $options = new CacheOptions(ttl: 60);

        // Mock cache miss
        $this->cache->expects($this->once())->method('get')->with($key)->willReturn(null);

        // Should store new data
        $this->cache->expects($this->once())->method('set');

        $promise = $this->manager->wrap($key, fn() => Create::promiseFor($newData), $options);
        $result = $promise->wait();

        $this->assertSame($newData, $result);
    }

    public function testReturnsStaleDataIfRateLimited() : void
    {
        $key = 'test_key';
        $staleData = 'stale_data';
        $options = new CacheOptions(
            ttl: 60,
            rate_limit_key: 'api_limit',
            serve_stale_if_limited: true
        );

        // Mock cache hit but EXPIRED (stale)
        $this->cache->expects($this->once())
            ->method('get')
            ->with($key)
            ->willReturn([
                'd' => $staleData,
                'e' => time() - 10 // Expired 10s ago
            ]);

        // Rate limiter says YES, we are limited
        $this->rateLimiter->expects($this->once())
            ->method('isLimited')
            ->with('api_limit')
            ->willReturn(true);

        // Factory should NOT be called
        $called = false;
        $factory = function() use (&$called) {
            $called = true;
            return Create::promiseFor('new');
        };

        $promise = $this->manager->wrap($key, $factory, $options);
        $result = $promise->wait();

        $this->assertSame($staleData, $result);
        $this->assertFalse($called, "Factory should not have been called");
    }

    public function testThrowsExceptionIfRateLimitedAndNoStaleData() : void
    {
        $key = 'test_key';
        $options = new CacheOptions(
            ttl: 60,
            rate_limit_key: 'api_limit',
            serve_stale_if_limited: true
        );

        // Mock cache MISS
        $this->cache->expects($this->once())->method('get')->willReturn(null);

        // Rate limited
        $this->rateLimiter->expects($this->once())->method('isLimited')->willReturn(true);

        $this->expectException(RateLimitException::class);

        $promise = $this->manager->wrap($key, fn() => Create::promiseFor('new'), $options);
        $promise->wait();
    }

    public function testRecordsExecutionWhenRateLimitKeyIsProvided() : void
    {
        $key = 'test_key';
        $options = new CacheOptions(rate_limit_key: 'api_limit');

        $this->cache->method('get')->willReturn(null);

        // Verify recordExecution is called
        $this->rateLimiter->expects($this->once())
            ->method('recordExecution')
            ->with('api_limit');

        $promise = $this->manager->wrap($key, fn() => Create::promiseFor('data'), $options);
        $promise->wait();
    }

    public function testDoesNotCacheIfTtlIsNull() : void
    {
        $key = 'test_key';
        $options = new CacheOptions(ttl: null);

        $this->cache->method('get')->willReturn(null);

        // Verify set is NEVER called
        $this->cache->expects($this->never())->method('set');

        $promise = $this->manager->wrap($key, fn() => Create::promiseFor('data'), $options);
        $promise->wait();
    }

    public function testClearsCache() : void
    {
        $this->cache->expects($this->once())
            ->method('clear')
            ->willReturn(true);

        $this->assertTrue($this->manager->clear());
    }

    public function testDeletesCacheKey() : void
    {
        $key = 'test_key';

        $this->cache->expects($this->once())
            ->method('delete')
            ->with($key)
            ->willReturn(true);

        $this->assertTrue($this->manager->delete($key));
    }
}
