<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Fyennyi\AsyncCache\AsyncCacheBuilder;
use Fyennyi\AsyncCache\CacheOptions;
use Fyennyi\AsyncCache\Enum\CacheStatus;
use Fyennyi\AsyncCache\Event\CacheStatusEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use function React\Async\async;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

/**
 * Enhanced PSR-16 Memory Cache with hit tracking
 */
class DemoCache implements \Psr\SimpleCache\CacheInterface {
    private array $data = [];
    public int $hits = 0;
    public int $misses = 0;

    public function get($key, $default = null): mixed {
        if (isset($this->data[$key])) { $this->hits++; return $this->data[$key]; }
        $this->misses++;
        return $default;
    }
    public function set($key, $value, $ttl = null): bool { $this->data[$key] = $value; return true; }
    public function delete($key): bool { unset($this->data[$key]); return true; }
    public function clear(): bool { $this->data = []; return true; }
    public function getMultiple($keys, $default = null): iterable { return []; }
    public function setMultiple($values, $ttl = null): bool { return true; }
    public function deleteMultiple($keys): bool { return true; }
    public function has($key): bool { return isset($this->data[$key]); }
}

/**
 * Event tracker to capture the status of the last cache operation
 */
class StatusTracker implements EventDispatcherInterface {
    public ?CacheStatus $lastStatus = null;
    public function dispatch(object $event): object {
        if ($event instanceof CacheStatusEvent) {
            $this->lastStatus = $event->status;
        }
        return $event;
    }
}

$cache = new DemoCache();
$tracker = new StatusTracker();
$manager = AsyncCacheBuilder::create($cache)
    ->withEventDispatcher($tracker)
    ->build();
$browser = new Browser();
$options = new CacheOptions(ttl: 10, stale_grace_period: 3600);

$http = new HttpServer(async(function (ServerRequestInterface $request) use ($manager, $browser, $options, $cache, $tracker) {
    $path = $request->getUri()->getPath();

    if ($path === '/' || $path === '/index.html') {
        $html = file_get_contents(__DIR__ . '/demo_ui.html');
        return new Response(200, ['Content-Type' => 'text/html'], $html);
    }

    if ($path === '/api/slow') {
        $startTime = microtime(true);
        try {
            $tracker->lastStatus = null;

            $result = $manager->wrap('georgia_flag', function() use ($browser) {
                return $browser->get('https://restcountries.com/v3.1/name/georgia')
                    ->then(function ($response) {
                        $data = json_decode((string)$response->getBody(), true);
                        if (empty($data)) throw new \RuntimeException("Empty API response");
                        return $data[0]['flags']['png'] ?? '';
                    });
            }, $options)->wait();

            $latency = round(microtime(true) - $startTime, 4);

            $source = 'network';
            if ($tracker->lastStatus === CacheStatus::Hit) $source = 'cache';
            if ($tracker->lastStatus === CacheStatus::Stale) $source = 'stale_fallback';

            // Singleflight detection: if no events fired but it was fast, it was coalesced
            if ($tracker->lastStatus === null && $latency < 0.1) $source = 'cache';

            return Response::json([
                'status' => 'success',
                'data' => $result,
                'latency' => $latency,
                'source' => $source
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'latency' => round(microtime(true) - $startTime, 4)
            ], 500);
        }
    }

    if ($path === '/api/fast') {
        return Response::json(['status' => 'success', 'data' => '⚡ Instant Response!']);
    }

    if ($path === '/api/stats') {
        return Response::json(['hits' => $cache->hits, 'misses' => $cache->misses]);
    }

    return new Response(404, [], 'Not Found');
}));

$socket = new SocketServer('0.0.0.0:8080');
$http->listen($socket);

echo "🚀 Async Demo Server running at http://127.0.0.1:8080\n";
\React\EventLoop\Loop::run();
