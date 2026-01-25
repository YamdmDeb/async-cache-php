<?php

namespace Fyennyi\AsyncCache\Serializer;

class PhpSerializer implements SerializerInterface
{
    public function serialize(mixed $data): string
    {
        return serialize($data);
    }

    public function unserialize(string $data): mixed
    {
        return unserialize($data);
    }
}
