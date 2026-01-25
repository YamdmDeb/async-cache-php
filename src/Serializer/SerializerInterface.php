<?php

namespace Fyennyi\AsyncCache\Serializer;

/**
 * Interface for data serialization in cache
 */
interface SerializerInterface
{
    /**
     * Serializes data into a string
     */
    public function serialize(mixed $data): string;

    /**
     * Unserializes data from a string
     */
    public function unserialize(string $data): mixed;
}
