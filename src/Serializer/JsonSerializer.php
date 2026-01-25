<?php

namespace Fyennyi\AsyncCache\Serializer;

class JsonSerializer implements SerializerInterface
{
    public function __construct(
        private int $options = 0
    ) {
    }

    public function serialize(mixed $data): string
    {
        return json_encode($data, $this->options | JSON_THROW_ON_ERROR);
    }

    public function unserialize(string $data): mixed
    {
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }
}
