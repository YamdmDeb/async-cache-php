<?php

namespace Fyennyi\AsyncCache\Serializer;

/**
 * High-performance serializer using igbinary extension with fallback to PHP serialize
 */
class IgbinarySerializer implements SerializerInterface
{
    private bool $supported;

    public function __construct()
    {
        $this->supported = extension_loaded('igbinary');
    }

    public function serialize(mixed $data): string
    {
        if ($this->supported) {
            return igbinary_serialize($data);
        }

        return serialize($data);
    }

    public function unserialize(string $data): mixed
    {
        if ($this->supported) {
            return igbinary_unserialize($data);
        }

        return unserialize($data);
    }
}
