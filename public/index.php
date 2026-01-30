<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Fyennyi\AsyncCache\AsyncCacheManager;
use Fyennyi\AsyncCache\CacheOptionsBuilder;
use Fyennyi\AsyncCache\Storage\ChainCacheAdapter;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use function React\Async\async;
use function React\Async\await;

// --- SIMPLE IN-MEMORY CACHE ADAPTERS ---

class MemoryAdapter implements \Psr\SimpleCache\CacheInterface
{
    public static array $data = [];
    public static array $expiry = [];

    public function get($key, $default = null) : mixed
    {
        if (isset(self::$expiry[$key]) && self::$expiry[$key] < time()) {
            $this->delete($key);
            return $default;
        }
        return self::$data[$key] ?? $default;
    }
    public function set($key, $value, $ttl = null) : bool
    {
        self::$data[$key] = $value;
        if (null !== $ttl) {
            self::$expiry[$key] = time() + (int)$ttl;
        } else {
            unset(self::$expiry[$key]);
        }
        return true;
    }
    public function delete($key) : bool
    {
        unset(self::$data[$key], self::$expiry[$key]);
        return true;
    }
    public function clear() : bool
    {
        self::$data = [];
        self::$expiry = [];
        return true;
    }
    public function getMultiple($keys, $default = null) : iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }
    public function setMultiple($values, $ttl = null) : bool
    {
        return true;
    }
    public function deleteMultiple($keys) : bool
    {
        return true;
    }
    public function has($key) : bool
    {
        if (isset(self::$expiry[$key]) && self::$expiry[$key] < time()) {
            $this->delete($key);
            return false;
        }
        return isset(self::$data[$key]);
    }
}

class SlowAdapter implements \Psr\SimpleCache\CacheInterface
{
    public static array $data = [];
    public function get($key, $default = null) : mixed
    {
        usleep(50000);
        return self::$data[$key] ?? $default;
    }
    public function set($key, $value, $ttl = null) : bool
    {
        usleep(50000);
        self::$data[$key] = $value;
        return true;
    }
    public function delete($key) : bool
    {
        unset(self::$data[$key]);
        return true;
    }
    public function clear() : bool
    {
        self::$data = [];
        return true;
    }
    public function getMultiple($keys, $default = null) : iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }
    public function setMultiple($values, $ttl = null) : bool
    {
        return true;
    }
    public function deleteMultiple($keys) : bool
    {
        return true;
    }
    public function has($key) : bool
    {
        return isset(self::$data[$key]);
    }
}

// --- EVENT TRACKING ---

class StatusTracker
{
    public ?string $lastStatus = null;
}
$tracker = new StatusTracker();

class EventTracker implements \Psr\EventDispatcher\EventDispatcherInterface
{
    public function __construct(private StatusTracker $tracker) {}
    public function dispatch(object $event) : object
    {
        if ($event instanceof \Fyennyi\AsyncCache\Event\CacheStatusEvent) {
            $this->tracker->lastStatus = $event->status->value;
        }
        return $event;
    }
}

// Console logger
class ConsoleLogger extends \Psr\Log\AbstractLogger
{
    public function log($level, $message, array $context = []) : void
    {
        if ('debug' === $level) {
            return;
        } // skip debug spam
        $ctx = !empty($context) ? ' ' . json_encode($context) : '';
        echo "[" . strtoupper($level) . "] {$message}{$ctx}\n";
    }
}

// --- CACHE MANAGERS ---

$l1Cache = new MemoryAdapter();
$l2Cache = new SlowAdapter();
// Note: $tracker is already defined above
$eventTracker = new EventTracker($tracker);
$logger = new ConsoleLogger();

$l1Manager = new AsyncCacheManager(
    AsyncCacheManager::configure($l1Cache)
        ->withEventDispatcher($eventTracker)
        ->withLogger($logger)
        ->build()
);

$l1l2Manager = new AsyncCacheManager(
    AsyncCacheManager::configure(new ChainCacheAdapter([$l1Cache, $l2Cache]))
        ->withEventDispatcher($eventTracker)
        ->withLogger($logger)
        ->build()
);

$browser = new Browser();

// --- HTTP SERVER ---

$http = new HttpServer(async(function (ServerRequestInterface $request) use ($l1Manager, $l1l2Manager, $browser, $tracker) {
    $path = $request->getUri()->getPath();

    // Serve index.html for root
    if ('/' === $path) {
        return new Response(200, ['Content-Type' => 'text/html'], file_get_contents(__DIR__ . '/index.html'));
    }

    // API: Demo endpoint with httpbin
    if ('/api/demo' === $path) {
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);

        $strategy = $params['strategy'] ?? 'strict';
        $endpoint = $params['endpoint'] ?? 'https://httpbin.org/delay/1';
        $ttl = (int)($params['ttl'] ?? 10);

        $start = microtime(true);
        $cacheKey = 'demo_' . md5($endpoint);

        try {
            if ('force_refresh' === $strategy) {
                unset(MemoryAdapter::$data[$cacheKey]);
            }

            // Build cache options using fluent builder with withStrategy()
            $cacheStrategy = match ($strategy) {
                'background' => \Fyennyi\AsyncCache\Enum\CacheStrategy::Background,
                'force_refresh' => \Fyennyi\AsyncCache\Enum\CacheStrategy::ForceRefresh,
                default => \Fyennyi\AsyncCache\Enum\CacheStrategy::Strict,
            };

            $options = CacheOptionsBuilder::create()
                ->withTtl($ttl)
                ->withStrategy($cacheStrategy)
                ->build();

            // Use AsyncCacheManager with coalesce protection
            $result = await($l1Manager->wrap($cacheKey, function () use ($browser, $endpoint) {
                return $browser->get($endpoint)->then(
                    fn ($r) => (string) $r->getBody()
                );
            }, $options));

            $latency = (microtime(true) - $start) * 1000;

            // Determine source from cache status event
            $status = $tracker->lastStatus ?? 'miss';
            $source = match ($status) {
                'hit', 'stale', 'x_fetch' => 'cache',
                default => 'api'
            };

            // Parse and truncate response for display
            $responsePreview = $result;
            if (strlen($result) > 500) {
                $responsePreview = substr($result, 0, 500) . '...';
            }

            return Response::json([
                'source' => $source,
                'latency' => round($latency, 2),
                'strategy' => $strategy,
                'data' => $responsePreview
            ]);
        } catch (\Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;
            return Response::json([
                'source' => 'error',
                'error' => $e->getMessage(),
                'latency' => round($latency, 2)
            ], 500);
        }
    }

    // API: Stats endpoint
    if ('/api/stats' === $path) {
        return Response::json([
            'hits' => $tracker->hits,
            'misses' => $tracker->misses
        ]);
    }

    // API: Clear all caches
    if ('/api/clear' === $path) {
        MemoryAdapter::$data = [];
        SlowAdapter::$data = [];
        return Response::json(['status' => 'cleared']);
    }

    return new Response(404, [], 'Not Found');
}));

$port = (int)(getenv('PORT') ?: 8080);
$socket = new SocketServer('0.0.0.0:' . $port);
$http->listen($socket);

echo "🚀 Demo site organized and running at http://0.0.0.0:$port\n";
\React\EventLoop\Loop::run();
