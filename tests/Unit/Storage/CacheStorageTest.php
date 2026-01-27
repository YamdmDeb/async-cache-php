<?php

namespace Tests\Unit\Storage;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Model\CachedItem;
use Fyennyi\AsyncCache\Serializer\PhpSerializer;
use Fyennyi\AsyncCache\Storage\AsyncCacheAdapterInterface;
use Fyennyi\AsyncCache\Storage\CacheStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CacheStorageTest extends TestCase
{
    private MockObject|AsyncCacheAdapterInterface $adapter;
    private CacheStorage $storage;

    protected function setUp() : void
    {
        $this->adapter = $this->createMock(AsyncCacheAdapterInterface::class);
        $this->storage = new CacheStorage($this->adapter, new NullLogger(), new PhpSerializer());
    }

    public function testGetReturnsNullOnMiss() : void
    {
        $d = new Deferred(); $d->resolve(null);
        $this->adapter->method('get')->willReturn($d->future());

        $this->assertNull($this->storage->get('key', new CacheOptions())->wait());
    }

    public function testGetReturnsUncompressedData() : void
    {
        $data = 'value';
        $item = new CachedItem($data, time() + 100);
        
        $d = new Deferred(); $d->resolve($item);
        $this->adapter->method('get')->willReturn($d->future());

        $res = $this->storage->get('key', new CacheOptions())->wait();
        $this->assertInstanceOf(CachedItem::class, $res);
        $this->assertSame($data, $res->data);
    }

    public function testSetCompressesData() : void
    {
        $data = str_repeat('a', 2000); // Should trigger compression
        $options = new CacheOptions(compression: true, compression_threshold: 100);

        $this->adapter->expects($this->once())->method('set')->willReturnCallback(function($k, $item, $ttl) {
            $this->assertTrue($item->is_compressed);
            $this->assertIsString($item->data); // Compressed binary string
            
            $d = new Deferred(); $d->resolve(true);
            return $d->future();
        });

        $this->assertTrue($this->storage->set('key', $data, $options)->wait());
    }

    public function testGetReturnsNullIfDecompressionFails() : void
    {
        // Corrupt compressed data
        $item = new CachedItem('not_valid_gzip_data', time() + 100, is_compressed: true);
        
        $d = new Deferred(); $d->resolve($item);
        $this->adapter->method('get')->willReturn($d->future());

        // Should log error and return null
        $this->assertNull($this->storage->get('key', new CacheOptions())->wait());
    }

    public function testGetReturnsNullIfItemIsNotCachedItem() : void
    {
        // Adapter returns something weird (e.g. raw string from a non-compliant adapter)
        $d = new Deferred(); $d->resolve('im_just_a_string');
        $this->adapter->method('get')->willReturn($d->future());

        $this->assertNull($this->storage->get('key', new CacheOptions())->wait());
    }
}
