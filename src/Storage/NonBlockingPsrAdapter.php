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

namespace Fyennyi\AsyncCache\Storage;

use Fyennyi\AsyncCache\Core\Deferred;
use Fyennyi\AsyncCache\Core\Future;
use React\ChildProcess\Process;
use React\EventLoop\Loop;

/**
 * Truly non-blocking PSR-16 adapter that offloads work to a child process
 */
class NonBlockingPsrAdapter implements AsyncCacheAdapterInterface
{
    private ?Process $process = null;
    /** @var array<string, Deferred> */
    private array $pending_requests = [];
    private string $buffer = '';

    /**
     * @param  string  $worker_script  Path to the PHP worker script
     */
    public function __construct(private string $worker_script)
    {
        $this->start_worker();
    }

    /**
     * Starts the background PHP process
     */
    private function start_worker() : void
    {
        $this->process = new Process("php " . escapeshellarg($this->worker_script));
        $this->process->start();

        $this->process->stdout->on('data', function ($chunk) {
            $this->buffer .= $chunk;
            while (($pos = strpos($this->buffer, "\n")) !== false) {
                $line = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);
                $this->handle_response(json_decode($line, true));
            }
        });

        $this->process->on('exit', function() {
            $this->process = null;
            // Auto-restart after a delay
            Loop::addTimer(1.0, fn() => $this->start_worker());
        });
    }

    /**
     * Routes the response from worker to the correct Deferred
     */
    private function handle_response(?array $response) : void
    {
        if ($response === null || !isset($response['id'])) return;

        $id = $response['id'];
        if (isset($this->pending_requests[$id])) {
            $deferred = $this->pending_requests[$id];
            unset($this->pending_requests[$id]);

            if (isset($response['error']) && $response['error'] !== null) {
                $deferred->reject(new \RuntimeException($response['error']));
            } else {
                $deferred->resolve($response['result']);
            }
        }
    }

    /**
     * Sends a command to the worker and returns a Future
     */
    private function send_command(string $cmd, array $args = []) : Future
    {
        $id = uniqid('req_', true);
        $deferred = new Deferred();
        $this->pending_requests[$id] = $deferred;

        $payload = json_encode(['id' => $id, 'cmd' => $cmd, 'args' => $args]) . "\n";

        if ($this->process && $this->process->stdin->isWritable()) {
            $this->process->stdin->write($payload);
        } else {
            $deferred->reject(new \RuntimeException("Worker process is not available"));
        }

        return $deferred->future();
    }

    /**
     * @inheritDoc
     */
    public function get(string $key) : Future
    {
        $deferred = new Deferred();
        $this->send_command('get', ['key' => $key])->onResolve(
            function($data) use ($deferred) {
                try {
                    // Result is always a serialized string (or null)
                    $deferred->resolve($data !== null ? unserialize((string)$data) : null);
                } catch (\Throwable $e) {
                    $deferred->reject($e);
                }
            },
            fn($e) => $deferred->reject($e)
        );
        return $deferred->future();
    }

    /**
     * @inheritDoc
     */
    public function getMultiple(iterable $keys) : Future
    {
        $deferred = new Deferred();
        $this->send_command('getMultiple', ['keys' => (array)$keys])->onResolve(
            function($data) use ($deferred) {
                try {
                    $results = [];
                    foreach ($data as $key => $val) {
                        $results[$key] = $val !== null ? unserialize((string)$val) : null;
                    }
                    $deferred->resolve($results);
                } catch (\Throwable $e) {
                    $deferred->reject($e);
                }
            },
            fn($e) => $deferred->reject($e)
        );
        return $deferred->future();
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value, ?int $ttl = null) : Future
    {
        return $this->send_command('set', [
            'key' => $key,
            'value' => serialize($value),
            'ttl' => $ttl
        ]);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key) : Future
    {
        return $this->send_command('delete', ['key' => $key]);
    }

    /**
     * @inheritDoc
     */
    public function clear() : Future
    {
        return $this->send_command('clear');
    }
}
