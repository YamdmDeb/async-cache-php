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

namespace Fyennyi\AsyncCache\Core;

/**
 * Manages the resolution state of a Future acting as the producer controller
 */
class Deferred
{
    private Future $future;

    public function __construct()
    {
        $this->future = new Future();
    }

    /**
     * Returns the future controlled by this deferred
     *
     * @return Future
     */
    public function future() : Future
    {
        return $this->future;
    }

    /**
     * Fulfills the future with a success value
     *
     * @param  mixed  $value  The result value
     * @return void
     */
    public function resolve(mixed $value) : void
    {
        $this->future->fulfill($value);
    }

    /**
     * Rejects the future with a failure reason
     *
     * @param  mixed  $reason  The failure reason
     * @return void
     */
    public function reject(mixed $reason) : void
    {
        $this->future->notifyFailure($reason);
    }
}
