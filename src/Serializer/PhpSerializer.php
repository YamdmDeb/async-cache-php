<?php

namespace Fyennyi\AsyncCache\Serializer;

/**
 * Standard PHP serialization implementation
 */
class PhpSerializer implements SerializerInterface
{
    /**
     * @param  mixed  $data  Data to serialize
     * @return string
     */
    public function serialize(mixed $data) : string
    {
        return serialize($data);
    }

    /**
     * @param  string  $data  Serialized string
     * @return mixed
     */
    public function unserialize(string $data) : mixed
    {
        return unserialize($data);
    }
}
