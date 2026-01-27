<?php

/*
 *
 *     _                          ____           _            ____  _   _ ____
 *    / \   ___ _   _ _ __   ___ / ___|__ _  ___| |__   ___  |  _ \| | | |  _ \
 *   / _ \ / __| | | | '_ \ / __| |   / _` |/ __| '_ \ / _ \ | |_) | |_| | |_) |
 *  / ___ \\__ \ |_| | | | | (__| |__| (_| | (__| | | |  __/ |  __/|  _  |  __/
 * /_/   \_\___/\__, |_| |_|\___|\____\__,_|\___|_| |_|\___| |_|   |_| |_|_|
 *              |___/
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author Serhii Cherneha
 * @link https://chernega.eu.org/
 *
 *
 */

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
