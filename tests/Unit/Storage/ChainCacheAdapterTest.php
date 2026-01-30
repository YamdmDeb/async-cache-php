<?php

namespace Tests\Unit\Storage;

use Fyennyi\AsyncCache\Storage\AsyncCacheAdapterInterface;
use Fyennyi\AsyncCache\Storage\ChainCacheAdapter;
use Fyennyi\AsyncCache\Storage\PsrToAsyncAdapter;
use Fyennyi\AsyncCache\Storage\ReactCacheAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use function React\Async\await;

class ChainCacheAdapterTest extends TestCase
{
    private MockObject|AsyncCacheAdapterInterface $l1;
    private MockObject|AsyncCacheAdapterInterface $l2;
    private ChainCacheAdapter $adapter;

    protected function setUp() : void
    {
        $this->l1 = $this->createMock(AsyncCacheAdapterInterface::class);
        $this->l2 = $this->createMock(AsyncCacheAdapterInterface::class);
        $this->adapter = new ChainCacheAdapter([$this->l1, $this->l2]);
    }

    public function testGetReturnsFromFirstLayer() : void
    {
        $d = new Deferred();
        $d->resolve('val1');
        $this->l1->method('get')->with('key')->willReturn($d->promise());
        $this->l2->expects($this->never())->method('get');

        $this->assertSame('val1', await($this->adapter->get('key')));
    }

    public function testGetFallsBackAndBackfills() : void
    {
        // L1 miss
        $d1 = new Deferred();
        $d1->resolve(null);
        $this->l1->method('get')->with('key')->willReturn($d1->promise());

        // L2 hit
        $d2 = new Deferred();
        $d2->resolve('val2');
        $this->l2->method('get')->with('key')->willReturn($d2->promise());

        // Expect L1 to be backfilled
        $this->l1->expects($this->once())->method('set')->with('key', 'val2');

        $this->assertSame('val2', await($this->adapter->get('key')));
    }

    public function testSetWritesToAllLayers() : void
    {
        $d1 = new Deferred();
        $d1->resolve(true);
        $this->l1->expects($this->once())->method('set')->with('k', 'v', 10)->willReturn($d1->promise());

        $d2 = new Deferred();
        $d2->resolve(true);
        $this->l2->expects($this->once())->method('set')->with('k', 'v', 10)->willReturn($d2->promise());

        $this->assertTrue(await($this->adapter->set('k', 'v', 10)));
    }

    public function testDeleteDeletesFromAllLayers() : void
    {
        $d1 = new Deferred();
        $d1->resolve(true);
        $this->l1->expects($this->once())->method('delete')->with('k')->willReturn($d1->promise());

        $d2 = new Deferred();
        $d2->resolve(true);
        $this->l2->expects($this->once())->method('delete')->with('k')->willReturn($d2->promise());

        $this->assertTrue(await($this->adapter->delete('k')));
    }

    public function testGetFallsBackOnFailure() : void
    {
        // L1 fails
        $this->l1->method('get')->willReturn(\React\Promise\reject(new \Exception('L1 fail')));

        // L2 hit
        $this->l2->method('get')->willReturn(\React\Promise\resolve('val2'));

        $this->assertSame('val2', await($this->adapter->get('key')));
    }

    public function testGetFallsBackOnNull() : void
    {
        $this->l1->method('get')->willReturn(\React\Promise\resolve(null));
        $this->l2->method('get')->willReturn(\React\Promise\resolve('val2'));
        $this->assertSame('val2', await($this->adapter->get('key')));
    }

    public function testGetFallsBackOnFailureAllLayers() : void
    {
        $this->l1->method('get')->willReturn(\React\Promise\reject(new \Exception('L1 fail')));
        $this->l2->method('get')->willReturn(\React\Promise\reject(new \Exception('L2 fail')));

        $this->assertNull(await($this->adapter->get('key')));
    }

    public function testGetMultiple() : void
    {
        $keys = ['k1', 'k2'];
        // L1 has k1, L2 has k2
        $this->l1->method('getMultiple')->with($keys)->willReturn(
            \React\Promise\resolve(['k1' => 'v1', 'k2' => null])
        );
        $this->l2->method('getMultiple')->with(['k2'])->willReturn(
            \React\Promise\resolve(['k2' => 'v2'])
        );
        // Backfill to L1
        $this->l1->expects($this->once())->method('set')->with('k2', 'v2');

        $res = await($this->adapter->getMultiple($keys));
        $this->assertSame(['k1' => 'v1', 'k2' => 'v2'], $res);
    }

    public function testGetMultipleEmpty() : void
    {
        $this->assertSame([], await($this->adapter->getMultiple([])));
    }

    public function testSetReturnsTrueAlways() : void
    {
        // Current implementation returns true even if some layers return false (best effort)
        $this->l1->method('set')->willReturn(\React\Promise\resolve(true));
        $this->l2->method('set')->willReturn(\React\Promise\resolve(false));

        $this->assertTrue(await($this->adapter->set('k', 'v')));
    }

    public function testClearClearsAllLayers() : void
    {
        $this->l1->expects($this->once())->method('clear')->willReturn(\React\Promise\resolve(true));
        $this->l2->expects($this->once())->method('clear')->willReturn(\React\Promise\resolve(true));

        $this->assertTrue(await($this->adapter->clear()));
    }

    public function testEmptyAdapters() : void
    {
        $adapter = new ChainCacheAdapter([]);
        $this->assertNull(await($adapter->get('k')));
        $this->assertTrue(await($adapter->set('k', 'v')));
        $this->assertTrue(await($adapter->delete('k')));
        $this->assertSame([], await($adapter->getMultiple(['k'])));
        $this->assertTrue(await($adapter->clear()));
    }

    public function testBackfillMultipleLayers() : void
    {
        $l3 = $this->createMock(AsyncCacheAdapterInterface::class);
        $adapter = new ChainCacheAdapter([$this->l1, $this->l2, $l3]);

        $this->l1->method('get')->willReturn(\React\Promise\resolve(null));
        $this->l2->method('get')->willReturn(\React\Promise\resolve(null));
        $l3->method('get')->willReturn(\React\Promise\resolve('found'));

        $this->l1->expects($this->once())->method('set')->with('k', 'found');
        $this->l2->expects($this->once())->method('set')->with('k', 'found');

        $this->assertSame('found', await($adapter->get('k')));
    }

    public function testConstructorWrapsDifferentAdapters() : void
    {
        $psr = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $react = $this->createMock(\React\Cache\CacheInterface::class);

        $adapter = new ChainCacheAdapter([$psr, $react]);

        $ref = new \ReflectionClass($adapter);
        $prop = $ref->getProperty('adapters');
        $prop->setAccessible(true);
        $adapters = $prop->getValue($adapter);

        $this->assertCount(2, $adapters);
        $this->assertInstanceOf(PsrToAsyncAdapter::class, $adapters[0]);
        $this->assertInstanceOf(ReactCacheAdapter::class, $adapters[1]);
    }
}
