<?php

namespace Tests\Unit\Core;

use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Core\CacheContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class CacheContextTest extends TestCase
{
    public function testGetElapsedTime() : void
    {
        $clock = new MockClock();
        $context = new CacheContext('test_key', fn () => 'data', new CacheOptions(), $clock);

        // Initially, elapsed time should be very small (close to 0)
        $elapsed1 = $context->getElapsedTime();
        $this->assertGreaterThanOrEqual(0, $elapsed1);
        $this->assertLessThan(0.1, $elapsed1);

        // Advance the clock by 1 second
        $clock->sleep(1.0);

        // Elapsed time should now be around 1 second
        $elapsed2 = $context->getElapsedTime();
        $this->assertGreaterThanOrEqual(1.0, $elapsed2);
        $this->assertLessThan(1.1, $elapsed2);

        // Advance the clock by another 0.5 seconds
        $clock->sleep(0.5);

        // Elapsed time should now be around 1.5 seconds
        $elapsed3 = $context->getElapsedTime();
        $this->assertGreaterThanOrEqual(1.5, $elapsed3);
        $this->assertLessThan(1.6, $elapsed3);
    }

    public function testGetElapsedTimeIncreases() : void
    {
        $clock = new MockClock();
        $context = new CacheContext('test_key', fn () => 'data', new CacheOptions(), $clock);

        $elapsed1 = $context->getElapsedTime();

        $clock->sleep(0.1);
        $elapsed2 = $context->getElapsedTime();

        $clock->sleep(0.2);
        $elapsed3 = $context->getElapsedTime();

        // Each subsequent call should return a larger value
        $this->assertLessThan($elapsed2, $elapsed1);
        $this->assertLessThan($elapsed3, $elapsed2);
    }
}
