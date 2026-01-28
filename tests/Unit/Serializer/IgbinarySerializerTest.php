<?php

namespace Fyennyi\AsyncCache\Serializer;

/**
 * Shadowing extension_loaded to simulate missing igbinary.
 */
function extension_loaded($name)
{
    if ('igbinary' === $name && !empty($GLOBALS['mock_igbinary_missing'])) {
        return false;
    }
    return \extension_loaded($name);
}

/**
 * Shadowing function_exists to simulate missing igbinary functions.
 */
function function_exists($name)
{
    if (str_contains($name, 'igbinary') && !empty($GLOBALS['mock_igbinary_missing'])) {
        return false;
    }
    return \function_exists($name);
}

namespace Tests\Unit\Serializer;

use Fyennyi\AsyncCache\Serializer\IgbinarySerializer;
use PHPUnit\Framework\TestCase;

class IgbinarySerializerTest extends TestCase
{
    protected function tearDown() : void
    {
        $GLOBALS['mock_igbinary_missing'] = false;
    }

    public function testThrowsExceptionIfExtensionNotLoaded() : void
    {
        $GLOBALS['mock_igbinary_missing'] = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('igbinary extension is not loaded');

        new IgbinarySerializer();
    }

    public function testSerializeThrowsIfFunctionMissing() : void
    {
        $GLOBALS['mock_igbinary_missing'] = true;

        $reflection = new \ReflectionClass(IgbinarySerializer::class);
        $serializer = $reflection->newInstanceWithoutConstructor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('igbinary extension is not loaded');

        $serializer->serialize(['data']);
    }

    public function testUnserializeThrowsIfFunctionMissing() : void
    {
        $GLOBALS['mock_igbinary_missing'] = true;

        $reflection = new \ReflectionClass(IgbinarySerializer::class);
        $serializer = $reflection->newInstanceWithoutConstructor();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('igbinary extension is not loaded');

        $serializer->unserialize('data');
    }

    public function testSerializeUnserialize() : void
    {
        if (!\extension_loaded('igbinary')) {
            $this->markTestSkipped('igbinary extension is not loaded');
        }

        $serializer = new IgbinarySerializer();
        $data = ['a' => 'b', 'c' => 1];

        $serialized = $serializer->serialize($data);
        $this->assertIsString($serialized);

        $unserialized = $serializer->unserialize($serialized);
        $this->assertSame($data, $unserialized);
    }
}
