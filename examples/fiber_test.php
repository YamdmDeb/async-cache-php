<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Fyennyi\AsyncCache\AsyncCacheBuilder;
use Fyennyi\AsyncCache\CacheOptions;
use function React\Async\async;

class SimpleMemoryCache implements \Psr\SimpleCache\CacheInterface {
    private array $data = [];
    public function get($key, $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set($key, $value, $ttl = null): bool { $this->data[$key] = $value; return true; }
    public function delete($key): bool { unset($this->data[$key]); return true; }
    public function clear(): bool { $this->data = []; return true; }
    public function getMultiple($keys, $default = null): iterable { return []; }
    public function setMultiple($values, $ttl = null): bool { return true; }
    public function deleteMultiple($keys): bool { return true; }
    public function has($key): bool { return isset($this->data[$key]); }
}

$cache = new SimpleMemoryCache();
$manager = AsyncCacheBuilder::create($cache)->build();

echo "--- Fiber (Cooperative Multitasking) Test ---
";

// TASK 1: Fiber-based request
$main = async(function() use ($manager) {
    echo "[Fiber] Starting non-blocking request...\n";
    $start = microtime(true);
    
    $result = $manager->get('fiber_key', function() {
        return \React\Promise\Timer\resolve(1.0)->then(fn() => "Honest Data");
    }, new CacheOptions());
    
    echo "[Fiber] COMPLETED! Result: $result (took " . number_format(microtime(true) - $start, 2) . "s)\n";
});

// TASK 2: Proof of life
$timer = \React\EventLoop\Loop::addPeriodicTimer(0.2, function() {
    echo "[Background] Loop is still spinning! Fibers are working correctly.\n";
});

echo "Main Thread: Starting Event Loop...\n";

$main()->then(function() use ($timer) {
    \React\EventLoop\Loop::cancelTimer($timer);
    \React\EventLoop\Loop::stop();
});

\React\EventLoop\Loop::run();
echo "--- Test Finished ---
";
