<?php

namespace Tests\Unit\Storage;

use Fyennyi\AsyncCache\Storage\ReactCacheAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use React\Cache\CacheInterface;
use React\Promise\Promise;

class ReactCacheAdapterTest extends TestCase
{
    private MockObject|CacheInterface $react;
    private ReactCacheAdapter $adapter;

    protected function setUp() : void
    {
        $this->react = $this->createMock(CacheInterface::class);
        $this->adapter = new ReactCacheAdapter($this->react);
    }

    public function testGet() : void
    {
        $this->react->expects($this->once())->method('get')->with('k')->willReturn(new Promise(function($resolve){ $resolve('v'); }));
        $this->assertSame('v', $this->adapter->get('k')->wait());
    }

    public function testSet() : void
    {
        $this->react->expects($this->once())->method('set')->with('k', 'v', 10)->willReturn(new Promise(function($resolve){ $resolve(true); }));
        $this->assertTrue($this->adapter->set('k', 'v', 10)->wait());
    }

    public function testDelete() : void
    {
        $this->react->expects($this->once())->method('delete')->with('k')->willReturn(new Promise(function($resolve){ $resolve(true); }));
        $this->assertTrue($this->adapter->delete('k')->wait());
    }
}
