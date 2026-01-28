<?php

namespace Tests\Unit;

use Fyennyi\AsyncCache\AsyncCacheManager;
use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Enum\CacheStrategy;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Storage\AsyncCacheAdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\LimiterInterface;
use function React\Async\await;

class AsyncCacheManagerTest extends TestCase
{
    private MockObject|CacheInterface $cache;
    private MockObject|LimiterInterface $rateLimiter;
    private LockFactory $lockFactory;
    private MockClock $clock;
    private AsyncCacheManager $manager;

    protected function setUp() : void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->rateLimiter = $this->createMock(LimiterInterface::class);
        $this->lockFactory = new LockFactory(new InMemoryStore()); // Use real in-memory locks
        $this->clock = new MockClock();

        $this->manager = new AsyncCacheManager(
            cache_adapter: $this->cache,
            rate_limiter: $this->rateLimiter,
            logger: new NullLogger(),
            lock_factory: $this->lockFactory,
            clock: $this->clock
        );
    }

    public function testReturnsFreshCacheImmediately() : void
    {
        $key = 'test_key';
        $data = 'cached_data';
        $options = new CacheOptions(ttl: 60);

        $cachedItem = new CachedItem($data, $this->clock->now()->getTimestamp() + 100);

        $this->cache->expects($this->once())
            ->method('get')
            ->with($key)
            ->willReturn($cachedItem);

        $promise = $this->manager->wrap($key, fn () => 'new_data', $options);
        $result = await($promise);

        $this->assertSame($data, $result);
    }

    public function testFetchesNewDataOnCacheMiss() : void
    {
        $key = 'test_key';
        $newData = 'new_data';
        $options = new CacheOptions(ttl: 60);

        $this->cache->expects($this->once())->method('get')->with($key)->willReturn(null);

        $promise = $this->manager->wrap($key, fn () => $newData, $options);
        $result = await($promise);

        $this->assertSame($newData, $result);
    }

    public function testForceRefreshStrategy() : void
    {
        $key = 'test_key';
        $newData = 'fresh_data';
        $options = new CacheOptions(ttl: 60, strategy: CacheStrategy::ForceRefresh);

        $this->cache->expects($this->once())->method('set');
        $this->cache->expects($this->never())->method('get');

        $promise = $this->manager->wrap($key, fn () => $newData, $options);
        $result = await($promise);

        $this->assertSame($newData, $result);
    }

    public function testClearsCache() : void
    {
        $this->cache->expects($this->once())
            ->method('clear')
            ->willReturn(true);

        $promise = $this->manager->clear();
        $result = await($promise);

        $this->assertTrue($result);
    }

    public function testDeletesCacheKey() : void
    {
        $key = 'test_key';

        $this->cache->expects($this->once())
            ->method('delete')
            ->with($key)
            ->willReturn(true);

        $promise = $this->manager->delete($key);
        $result = await($promise);

        $this->assertTrue($result);
    }

    public function testIncrementTimeout() : void
    {
        // Create isolated mocks to simulate timeout behavior for increment()
        $adapter = $this->createMock(AsyncCacheAdapterInterface::class);
        $lock = $this->createMock('\Symfony\Component\Lock\SharedLockInterface');
        $lockFactory = $this->createMock(LockFactory::class);

        $lockFactory->method('createLock')->willReturn($lock);
        // First call to acquire(false) simulates timeout
        $lock->method('acquire')->willReturn(false);

        $clock = new MockClock();
        $mgr = new AsyncCacheManager($adapter, lock_factory: $lockFactory, clock: $clock);

        // Advance clock asynchronously after the first attempt
        \React\EventLoop\Loop::addTimer(0.01, fn () => $clock->sleep(11.0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not acquire lock for incrementing key');

        await($mgr->increment('k'));
    }
    public function testIncrementAcquiresLockAndUpdatesValue() : void
    {
        $key = 'counter';
        $cache = $this->createMock(CacheInterface::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock('\Symfony\Component\Lock\SharedLockInterface');
        $lockFactory->method('createLock')->with('lock:counter:' . $key)->willReturn($lock);
        $lock->method('acquire')->willReturn(true);

        $cache->expects($this->once())->method('get')->with($key)->willReturn(new CachedItem(10, $this->clock->now()->getTimestamp() + 3600));
        $cache->expects($this->once())->method('set')->with($key, $this->callback(function ($item) {
            return $item instanceof CachedItem && 11 === $item->data;
        }))->willReturn(true);

        $mgr = new AsyncCacheManager(cache_adapter: $cache, rate_limiter: null, logger: new NullLogger(), lock_factory: $lockFactory, clock: $this->clock);
        $result = await($mgr->increment($key, 1));
        $this->assertSame(11, $result);
    }

    public function testIncrementInitializesValueIfMissing() : void
    {
        $key = 'counter';
        $cache = $this->createMock(CacheInterface::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock('\Symfony\Component\Lock\SharedLockInterface');
        $lockFactory->method('createLock')->with('lock:counter:' . $key)->willReturn($lock);
        $lock->method('acquire')->willReturn(true);

        $cache->method('get')->with($key)->willReturn(null);
        $cache->expects($this->once())->method('set')->with($key, $this->callback(function ($item) {
            return $item instanceof CachedItem && 1 === $item->data;
        }))->willReturn(true);

        $mgr = new AsyncCacheManager(cache_adapter: $cache, rate_limiter: null, logger: new NullLogger(), lock_factory: $lockFactory, clock: $this->clock);
        $result = await($mgr->increment($key));
        $this->assertSame(1, $result);
    }

    public function testDecrement() : void
    {
        $key = 'counter';
        $cache = $this->createMock(CacheInterface::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock('\Symfony\Component\Lock\SharedLockInterface');
        $lockFactory->method('createLock')->with('lock:counter:' . $key)->willReturn($lock);
        $lock->method('acquire')->willReturn(true);

        $cache->method('get')->with($key)->willReturn(new CachedItem(10, $this->clock->now()->getTimestamp() + 3600));
        $cache->expects($this->once())->method('set')->with($key, $this->callback(function ($item) {
            return $item instanceof CachedItem && 5 === $item->data;
        }))->willReturn(true);

        $mgr = new AsyncCacheManager(cache_adapter: $cache, rate_limiter: null, logger: new NullLogger(), lock_factory: $lockFactory, clock: $this->clock);
        $result = await($mgr->decrement($key, 5));
        $this->assertSame(5, $result);
    }

    public function testInvalidateTags() : void
    {
        $cache = $this->createMock(CacheInterface::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $mgr = new AsyncCacheManager(cache_adapter: $cache, rate_limiter: null, logger: new NullLogger(), lock_factory: $lockFactory, clock: $this->clock);

        $tags = ['tag1','tag2'];
        $cache->expects($this->exactly(2))->method('set')->with($this->stringStartsWith('tag_v:'));
        $this->assertTrue(await($mgr->invalidateTags($tags)));
    }

    public function testClearAndRateLimiter() : void
    {
        $this->rateLimiter->expects($this->once())->method('reset');
        $this->manager->clearRateLimiter();
        $this->assertSame($this->rateLimiter, $this->manager->getRateLimiter());
    }

    public function testConstructorWrappers() : void
    {
        $psr = $this->createMock(CacheInterface::class);
        $mgr = new AsyncCacheManager($psr);
        $this->assertInstanceOf(AsyncCacheManager::class, $mgr);

        $react = $this->createMock(\React\Cache\CacheInterface::class);
        $mgr2 = new AsyncCacheManager($react);
        $this->assertInstanceOf(AsyncCacheManager::class, $mgr2);
    }

    public function testIncrementHandlesGetFailure() : void
    {
        // Use fail_safe: false to ensure the promise is rejected on error
        $options = new CacheOptions(fail_safe: false);
        $this->cache->method('get')->willThrowException(new \Exception('Get fail'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Get fail');

        await($this->manager->increment('k', 1, $options));
    }

    public function testIncrementHandlesSetFailure() : void
    {
        $this->cache->method('get')->willReturn(null);
        // PsrToAsyncAdapter will convert sync exception to rejected promise
        $this->cache->method('set')->willThrowException(new \Exception('Set fail'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Set fail');

        await($this->manager->increment('k'));
    }

    public function testIncrementHandlesProcessingThrowable() : void
    {
        $serializer = $this->createMock(\Fyennyi\AsyncCache\Serializer\SerializerInterface::class);
        // Throwing in serialize() will trigger the catch (\Throwable $e) block in increment()
        $serializer->method('serialize')->willThrowException(new \Error('Sync fail'));

        $mgr = new AsyncCacheManager($this->cache, serializer: $serializer, clock: $this->clock);
        $this->cache->method('get')->willReturn(null);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Sync fail');

        // Compression: true triggers the serializer call in CacheStorage::set
        await($mgr->increment('k', 1, new CacheOptions(compression: true)));
    }

    public function testIncrementRetriesIfLockBusy() : void
    {
        $key = 'k';
        $lock = $this->createMock(\Symfony\Component\Lock\SharedLockInterface::class);
        $lockFactory = $this->createMock(\Symfony\Component\Lock\LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        // First call returns false (busy), second call returns true (acquired)
        $lock->expects($this->exactly(2))
            ->method('acquire')
            ->willReturnOnConsecutiveCalls(false, true);

        $this->cache->method('get')->willReturn(null);
        $this->cache->method('set')->willReturn(true);

        $mgr = new AsyncCacheManager($this->cache, lock_factory: $lockFactory, clock: $this->clock);

        $res = await($mgr->increment($key, 1));
        $this->assertSame(1, $res);
    }
}
