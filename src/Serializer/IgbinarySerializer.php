<?php

namespace Fyennyi\AsyncCache\Serializer;

/**
 * High-performance serializer using the igbinary PHP extension
 */
class IgbinarySerializer implements SerializerInterface
{
    /** @var bool Whether igbinary extension is active */
    private bool $supported;

    public function __construct()
    {
        $this->supported = extension_loaded('igbinary');
    }

    /**
     * @param  mixed  $data  Data to serialize
     * @return string
     */
    public function serialize(mixed $data) : string
    {
        if ($this->supported) {
            return igbinary_serialize($data);
        }

        return serialize($data);
    }

    /**
     * @param  string  $data  Serialized string
     * @return mixed
     */
    public function unserialize(string $data) : mixed
    {
        if ($this->supported) {
            return igbinary_unserialize($data);
        }

        return unserialize($data);
    }
}
