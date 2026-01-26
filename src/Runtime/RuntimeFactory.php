<?php

namespace Fyennyi\AsyncCache\Runtime;

/**
 * Automatically detects and creates the best runtime for the current environment
 */
class RuntimeFactory
{
    public static function create(): RuntimeInterface
    {
        if (FiberRuntime::isSupported()) {
            return new FiberRuntime();
        }

        if (ReactRuntime::isSupported()) {
            return new ReactRuntime();
        }

        return new NativeRuntime();
    }
}
