<?php

namespace Fyennyi\AsyncCache\Serializer;

/**
 * Interface for data serialization in cache
 */
interface SerializerInterface
{
    /**
     * Transforms data into its string representation
     *
     * @param  mixed  $data  Data to serialize
     * @return string        Serialized representation
     */
    public function serialize(mixed $data) : string;

    /**
     * Reconstructs original data from its serialized string
     *
     * @param  string  $data  Serialized string
     * @return mixed          Reconstructed original data
     */
    public function unserialize(string $data) : mixed;
}
