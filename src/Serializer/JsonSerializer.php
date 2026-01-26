<?php

namespace Fyennyi\AsyncCache\Serializer;

/**
 * JSON-based serialization implementation
 */
class JsonSerializer implements SerializerInterface
{
    /**
     * @param  int  $options  Bitmask of json_encode options
     */
    public function __construct(
        private int $options = 0
    ) {
    }

    /**
     * @param  mixed  $data  Data to serialize
     * @return string
     */
    public function serialize(mixed $data) : string
    {
        return json_encode($data, $this->options | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  string  $data  Serialized JSON string
     * @return mixed
     */
    public function unserialize(string $data) : mixed
    {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
}
