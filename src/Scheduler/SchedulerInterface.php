<?php

namespace Fyennyi\AsyncCache\Scheduler;

use Fyennyi\AsyncCache\Core\Future;

/**
 * Interface for async task scheduling using library-native Futures
 */
interface SchedulerInterface
{
    /**
     * Returns a Future that resolves after the specified delay
     */
    public function delay(float $seconds): Future;

    /**
     * Resolves a value into a native Future
     */
    public function resolve(mixed $value): Future;

    /**
     * Detects if this scheduler is supported
     */
    public static function isSupported(): bool;
}