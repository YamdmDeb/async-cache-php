<?php

namespace Tests\Unit\Event;

use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheHitEvent;
use Fyennyi\AsyncCache\Event\CacheMissEvent;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Fyennyi\AsyncCache\Event\RateLimitExceededEvent;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testCacheHitEvent() : void
    {
        $event = new CacheHitEvent('key', 'val');
        $this->assertSame('key', $event->key);
        $this->assertSame('val', $event->data);
    }

    public function testCacheMissEvent() : void
    {
        $event = new CacheMissEvent('key');
        $this->assertSame('key', $event->key);
    }

    public function testCacheStatusEvent() : void
    {
        $event = new CacheStatusEvent('key', CacheStatus::Hit, 0.1, ['tag']);
        $this->assertSame('key', $event->key);
        $this->assertSame(CacheStatus::Hit, $event->status);
        $this->assertSame(0.1, $event->latency);
        $this->assertSame(['tag'], $event->tags);
    }

    public function testRateLimitExceededEvent() : void
    {
        $event = new RateLimitExceededEvent('key', 'limit_key');
        $this->assertSame('key', $event->key);
        $this->assertSame('limit_key', $event->rate_limit_key);
    }
}
