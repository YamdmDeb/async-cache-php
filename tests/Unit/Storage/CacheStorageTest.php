<?php

namespace Tests\Unit\Storage;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Serializer\PhpSerializer;
use Fyennyi\AsyncCache\Storage\AsyncCacheAdapterInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Promise\Deferred;
use Symfony\Component\Clock\MockClock;
use function React\Async\await;

class CacheStorageTest extends TestCase
{
    private MockObject|AsyncCacheAdapterInterface $adapter;
    private MockClock $clock;
    private CacheStorage $storage;

    protected function setUp() : void
    {
        $this->adapter = $this->createMock(AsyncCacheAdapterInterface::class);
        $this->clock = new MockClock();
        $this->storage = new CacheStorage($this->adapter, new NullLogger(), new PhpSerializer(), $this->clock);
    }

    public function testGetReturnsNullOnMiss() : void
    {
        $d = new Deferred();
        $d->resolve(null);
        $this->adapter->method('get')->willReturn($d->promise());

        $this->assertNull(await($this->storage->get('key', new CacheOptions())));
    }

    public function testGetReturnsUncompressedData() : void
    {
        $data = 'value';
        $item = new CachedItem($data, $this->clock->now()->getTimestamp() + 100);

        $d = new Deferred();
        $d->resolve($item);
        $this->adapter->method('get')->willReturn($d->promise());

        $res = await($this->storage->get('key', new CacheOptions()));
        $this->assertInstanceOf(CachedItem::class, $res);
        $this->assertSame($data, $res->data);
    }

    public function testSetCompressesData() : void
    {
        $data = str_repeat('a', 2000); // Should trigger compression
        $options = new CacheOptions(compression: true, compression_threshold: 100);

        $this->adapter->expects($this->once())->method('set')->willReturnCallback(function ($k, $item, $ttl) {
            $this->assertTrue($item->is_compressed);
            $this->assertIsString($item->data); // Compressed binary string

            $d = new Deferred();
            $d->resolve(true);

            return $d->promise();
        });

        $this->assertTrue(await($this->storage->set('key', $data, $options)));
    }

    public function testGetReturnsNullIfDecompressionFails() : void
    {
        // Corrupt compressed data
        $item = new CachedItem('not_valid_gzip_data', $this->clock->now()->getTimestamp() + 100, is_compressed: true);

        $d = new Deferred();
        $d->resolve($item);
        $this->adapter->method('get')->willReturn($d->promise());

        // Should log error and return null
        $this->assertNull(await($this->storage->get('key', new CacheOptions())));
    }

    public function testGetReturnsNullIfItemIsNotCachedItem() : void
    {
        // Adapter returns something weird (e.g. raw string from a non-compliant adapter)
        $d = new Deferred();
        $d->resolve('im_just_a_string');
        $this->adapter->method('get')->willReturn($d->promise());

        $this->assertNull(await($this->storage->get('key', new CacheOptions())));
    }

    public function testGetDecompressesDataSuccessfully() : void
    {
        $data = 'to_compress';
        $serialized = (new PhpSerializer())->serialize($data);
        $compressed = gzcompress($serialized);
        $item = new CachedItem($compressed, $this->clock->now()->getTimestamp() + 100, is_compressed: true);

        $this->adapter->method('get')->willReturn(\React\Promise\resolve($item));

        $res = await($this->storage->get('key', new CacheOptions()));
        $this->assertSame($data, $res->data);
        $this->assertFalse($res->is_compressed);
    }

    public function testGetHandlesBackwardCompatibilityArray() : void
    {
        $data = 'old_data';
        $expire = $this->clock->now()->getTimestamp() + 3600;
        $d = new Deferred();
        $d->resolve(['d' => $data, 'e' => $expire]);
        $this->adapter->method('get')->willReturn($d->promise());

        $res = await($this->storage->get('key', new CacheOptions()));
        $this->assertInstanceOf(CachedItem::class, $res);
        $this->assertSame($data, $res->data);
        $this->assertSame($expire, $res->logical_expire_time);
    }

    public function testGetHandlesAdapterExceptionWithFailSafe() : void
    {
        $this->adapter->method('get')->willReturn(\React\Promise\reject(new \Exception('Storage failed')));

        $res = await($this->storage->get('key', new CacheOptions(fail_safe: true)));
        $this->assertNull($res);
    }

    public function testGetThrowsWhenNotFailSafe() : void
    {
        $this->adapter->method('get')->willReturn(\React\Promise\reject(new \Exception('Storage failed')));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Storage failed');

        await($this->storage->get('key', new CacheOptions(fail_safe: false)));
    }

    public function testSetSkipsCompressionIfBelowThreshold() : void
    {
        $data = 'short';
        $options = new CacheOptions(compression: true, compression_threshold: 100);

        $this->adapter->expects($this->once())->method('set')->willReturnCallback(function ($k, $item) {
            $this->assertFalse($item->is_compressed);
            return \React\Promise\resolve(true);
        });

        await($this->storage->set('key', $data, $options));
    }

    public function testInvalidateTagsEmpty() : void
    {
        $this->assertTrue(await($this->storage->invalidateTags([])));
    }

    public function testInvalidateTags() : void
    {
        $tags = ['t1', 't2'];
        $this->adapter->expects($this->exactly(2))->method('set')->willReturn(\React\Promise\resolve(true));

        $this->assertTrue(await($this->storage->invalidateTags($tags)));
    }

    public function testFetchTagVersionsEmpty() : void
    {
        $res = await($this->storage->fetchTagVersions([]));
        $this->assertSame([], $res);
    }

    public function testSetCreatesMultipleMissingTags() : void
    {
        $options = new CacheOptions(tags: ['t1']);

        // 1. fetchTagVersions calls getMultiple
        $this->adapter->expects($this->once())->method('getMultiple')->willReturn(\React\Promise\resolve([]));

        // 2. Since missing and create_missing=true (from set), it should set tag version
        $this->adapter->expects($this->exactly(2))->method('set')->willReturn(\React\Promise\resolve(true));

        await($this->storage->set('key', 'data', $options));
    }

    public function testDeleteMethod() : void
    {
        $this->adapter->expects($this->once())->method('delete')->with('k')->willReturn(\React\Promise\resolve(true));
        $this->assertTrue(await($this->storage->delete('k')));
    }

    public function testClearMethod() : void
    {
        $this->adapter->expects($this->once())->method('clear')->willReturn(\React\Promise\resolve(true));
        $this->assertTrue(await($this->storage->clear()));
    }

    public function testGetAdapter() : void
    {
        $this->assertSame($this->adapter, $this->storage->getAdapter());
    }

    public function testGetTagVersionsFailure() : void
    {
        $this->adapter->method('getMultiple')->willReturn(\React\Promise\resolve(['tag_v:t1' => null]));
        $this->adapter->expects($this->once())->method('set')->willReturn(\React\Promise\resolve(true));

        $res = await($this->storage->fetchTagVersions(['t1'], true));
        $this->assertArrayHasKey('t1', $res);
        $this->assertNotEmpty($res['t1']);
    }

    public function testGetTagVersionsReturnsNonArray() : void
    {
        $this->adapter->method('getMultiple')->willReturn(\React\Promise\resolve(null));
        $res = await($this->storage->fetchTagVersions(['t1']));
        $this->assertSame(['t1' => ''], $res);
    }

    public function testGetTagVersionsReturnsEmptyStringForNonScalar() : void
    {
        $this->adapter->method('getMultiple')->willReturn(\React\Promise\resolve(['tag_v:t1' => []])); // array is non-scalar
        $res = await($this->storage->fetchTagVersions(['t1']));
        $this->assertSame(['t1' => ''], $res);
    }

    public function testSetCreatesMultipleMissingTagsWithError() : void
    {
        $options = new CacheOptions(tags: ['t1']);
        $this->adapter->method('getMultiple')->willReturn(\React\Promise\resolve([]));
        // Simulate one set failing
        $this->adapter->method('set')->willReturn(\React\Promise\resolve(false));

        await($this->storage->set('key', 'data', $options));
        $this->assertTrue(true); // Should not throw
    }

    public function testSetHandlesException() : void
    {
        $this->adapter->method('set')->willThrowException(new \Exception('Fail'));
        $this->expectException(\Exception::class);
        await($this->storage->set('k', 'v', new CacheOptions()));
    }
}
