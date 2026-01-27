<?php

namespace Tests\Unit\Serializer;

use Fyennyi\AsyncCache\Serializer\JsonSerializer;
use Fyennyi\AsyncCache\Serializer\PhpSerializer;
use Fyennyi\AsyncCache\Serializer\IgbinarySerializer;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    public function testPhpSerializer() : void
    {
        $serializer = new PhpSerializer();
        $data = ['foo' => 'bar', 123];

        $serialized = $serializer->serialize($data);
        $this->assertIsString($serialized);
        
        $unserialized = $serializer->unserialize($serialized);
        $this->assertSame($data, $unserialized);
    }

    public function testJsonSerializer() : void
    {
        $serializer = new JsonSerializer();
        $data = ['foo' => 'bar', 'nested' => ['a' => 1]];

        $serialized = $serializer->serialize($data);
        $this->assertJson($serialized);

        $unserialized = $serializer->unserialize($serialized);
        $this->assertSame($data, $unserialized);
    }
    
    public function testJsonSerializerThrowsOnInvalidData() : void
    {
        $serializer = new JsonSerializer();
        $this->expectException(\JsonException::class);
        $serializer->serialize("\xB1\x31"); // Invalid UTF-8
    }

    public function testJsonSerializerThrowsOnInvalidJson() : void
    {
        $serializer = new JsonSerializer();
        $this->expectException(\JsonException::class);
        $serializer->unserialize('{invalid_json');
    }

    public function testJsonSerializerWithOptions() : void
    {
        $serializer = new JsonSerializer(JSON_PRETTY_PRINT);
        $data = ['a' => 1];
        
        $serialized = $serializer->serialize($data);
        // Should contain newlines due to pretty print
        $this->assertStringContainsString("\n", $serialized);
        
        $unserialized = $serializer->unserialize($serialized);
        $this->assertSame($data, $unserialized);
    }
}
