<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Fyennyi\AsyncCache\AsyncCacheBuilder;
use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Storage\ChainCacheAdapter;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Browser;
use function React\Async\async;
use function React\Async\await;

// --- ADAPTERS ---

/**
 * L1: Fast Memory Cache
 */
class MemoryAdapter implements \Psr\SimpleCache\CacheInterface {
    public static array $data = [];
    public function get($key, $default = null): mixed { return self::$data[$key] ?? $default; }
    public function set($key, $value, $ttl = null): bool { self::$data[$key] = $value; return true; }
    public function delete($key): bool { unset(self::$data[$key]); return true; }
    public function clear(): bool { self::$data = []; return true; }
    public function getMultiple($keys, $default = null): iterable { return []; }
    public function setMultiple($values, $ttl = null): bool { return true; }
    public function deleteMultiple($keys): bool { return true; }
    public function has($key): bool { return isset(self::$data[$key]); }
}

/**
 * L2: Slow Simulated Cache (e.g. Redis over network)
 */
class SlowAdapter implements \Psr\SimpleCache\CacheInterface {
    public static array $data = [];
    public function get($key, $default = null): mixed { 
        usleep(50000); // 50ms latency simulation
        return self::$data[$key] ?? $default; 
    }
    public function set($key, $value, $ttl = null): bool { 
        usleep(50000);
        self::$data[$key] = $value; return true; 
    }
    public function delete($key): bool { unset(self::$data[$key]); return true; }
    public function clear(): bool { self::$data = []; return true; }
    public function getMultiple($keys, $default = null): iterable { return []; }
    public function setMultiple($values, $ttl = null): bool { return true; }
    public function deleteMultiple($keys): bool { return true; }
    public function has($key): bool { return isset(self::$data[$key]); }
}

// --- TELEMETRY ---

class StatsTracker {
    public int $hits = 0;
    public int $misses = 0;
}
$tracker = new StatsTracker();

class TelemetryDispatcher implements \Psr\EventDispatcher\EventDispatcherInterface {
    public function __construct(private StatsTracker $tracker) {}
    public function dispatch(object $event): object {
        if ($event instanceof \Fyennyi\AsyncCache\Event\CacheStatusEvent) {
            if ($event->status === \Fyennyi\AsyncCache\Enum\CacheStatus::Hit) $this->tracker->hits++;
            if ($event->status === \Fyennyi\AsyncCache\Enum\CacheStatus::Miss) $this->tracker->misses++;
        }
        return $event;
    }
}

// --- MANAGERS ---

$cache = new MemoryAdapter();
$dispatcher = new TelemetryDispatcher($tracker);

// 1. Memory Only Manager (for main dashboard)
$memoryManager = AsyncCacheBuilder::create($cache)
    ->withEventDispatcher($dispatcher)
    ->build();

// 2. Chain Manager (Memory + Slow) for benchmark
$chainManager = AsyncCacheBuilder::create(new ChainCacheAdapter([
    new MemoryAdapter(),
    new SlowAdapter()
]))->build();

$browser = new Browser();
$options = new CacheOptions(ttl: 10);

// --- SERVER ---

$http = new HttpServer(async(function (ServerRequestInterface $request) use ($memoryManager, $chainManager, $browser, $options, $tracker) {
    $path = $request->getUri()->getPath();

    // --- STATIC FILES ---
    
    // Serve dashboard.html as the primary entry point
    if ($path === '/' || $path === '/dashboard') {
        return new Response(200, ['Content-Type' => 'text/html'], file_get_contents(__DIR__ . '/dashboard.html'));
    }
    
    if ($path === '/benchmark') {
        return new Response(200, ['Content-Type' => 'text/html'], file_get_contents(__DIR__ . '/benchmark.html'));
    }

    // --- API ENDPOINTS ---

    // 1. Slow API Demo (Coalescing Showcase)
    if ($path === '/api/slow') {
        $start = microtime(true);
        try {
            $res = $memoryManager->wrap('georgia_flag', function() use ($browser) {
                return $browser->get('https://restcountries.com/v3.1/name/georgia')
                    ->then(function ($response) {
                        $data = json_decode((string)$response->getBody(), true);
                        return $data[0]['flags']['png'] ?? '';
                    });
            }, new CacheOptions(ttl: 15))->wait();

            return Response::json([
                'latency' => round(microtime(true) - $start, 4),
                'data' => $res,
                'source' => 'AsyncCache'
            ]);
        } catch (\Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // 2. Fast API (immediate response)
    if ($path === '/api/fast') {
        return Response::json(['data' => 'Fast Response ' . time()]);
    }

    // 3. Memory Benchmark
    if ($path === '/api/memory') {
        $start = microtime(true);
        $res = $memoryManager->wrap('shared_benchmark', function() use ($browser) {
            return await($browser->get('https://www.google.com')->then(fn() => "Origin OK"));
        }, $options)->wait();

        return Response::json([
            'latency' => round(microtime(true) - $start, 4),
            'data' => $res,
            'type' => 'Memory (L1)'
        ]);
    }

    // 4. Chain Benchmark (L1 + L2)
    if ($path === '/api/chain') {
        $start = microtime(true);
        $res = $chainManager->wrap('shared_benchmark', function() use ($browser) {
            return await($browser->get('https://www.google.com')->then(fn() => "Origin OK"));
        }, $options)->wait();

        return Response::json([
            'latency' => round(microtime(true) - $start, 4),
            'data' => $res,
            'type' => 'Chain (L1+L2)'
        ]);
    }

    // 5. Stats Endpoint for Dashboard
    if ($path === '/api/stats') {
        return Response::json([
            'hits' => $tracker->hits,
            'misses' => $tracker->misses
        ]);
    }

    // 6. Clear Cache
    if ($path === '/api/clear') {
        $memoryManager->clear();
        return Response::json(['status' => 'success']);
    }

    return new Response(404, [], 'Not Found');
}));

$port = (int)(getenv('PORT') ?: 8080);
$socket = new SocketServer('0.0.0.0:' . $port);
$http->listen($socket);

echo "🚀 Demo site organized and running at http://0.0.0.0:$port\n";
\React\EventLoop\Loop::run();
