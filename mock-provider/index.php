<?php

declare(strict_types=1);

/*
 * Mock notification provider.
 *
 * Implements the provider contract from the brief so the system can be run,
 * tested and measured without an external service:
 *
 *   POST /send  {"to": "+905551234567", "channel": "sms", "content": "..."}
 *   202         {"messageId": "<uuid>", "status": "accepted", "timestamp": "<ISO 8601>"}
 *
 * On top of the contract it does what a stand-in for a real provider needs:
 *
 *   - Honours the Idempotency-Key header: a message sent twice with the same
 *     key is delivered once and answered with the original messageId.
 *   - Injects faults on request: random 5xx, 429 with Retry-After, a full
 *     outage, slow responses, and "lost" responses (the message is accepted
 *     but the caller gets an error, as when a reply is lost on the network).
 *   - Records the arrival time of every request, so the per-second rate can
 *     be measured where it matters: at the provider.
 *
 * Admin endpoints (demo only): GET /__admin/stats, POST /__admin/config,
 * POST /__admin/reset, GET /__admin/health, GET /metrics.
 *
 * A single file on purpose: it is a test double, not part of the product.
 */

const CHANNELS = ['sms', 'email', 'push'];
const OUTCOMES = ['accepted', 'replayed', 'failed', 'throttled', 'lost'];
const MAX_ARRIVALS_KEPT = 200_000;

/**
 * @param  array<string, string>  $headers
 * @return never
 */
function respond(int $status, mixed $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');

    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }

    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function connect(): Redis
{
    $port = getenv('REDIS_PORT');
    $database = getenv('MOCK_PROVIDER_REDIS_DB');

    $redis = new Redis;
    $redis->pconnect(getenv('REDIS_HOST') ?: 'redis', is_numeric($port) ? (int) $port : 6379, 1.0, 'mock-provider');
    // Its own database: nothing here is shared with the application.
    $redis->select(is_numeric($database) ? (int) $database : 3);

    return $redis;
}

function nowMicros(): int
{
    return (int) (microtime(true) * 1_000_000);
}

function isoTime(int $micros): string
{
    return gmdate('Y-m-d\TH:i:s', intdiv($micros, 1_000_000)).sprintf('.%03dZ', intdiv($micros % 1_000_000, 1000));
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Fault-injection settings, all off by default.
 *
 * @return array{latency_ms: int, jitter_ms: int, fail_rate: float, fail_status: int, lose_response_rate: float, retry_after: int, throttle_until: int, down_until: int}
 */
function settings(Redis $redis): array
{
    $stored = $redis->hGetAll('mock:config');
    $stored = is_array($stored) ? $stored : [];

    $number = static fn (string $key, float $default): float => is_numeric($stored[$key] ?? null) ? (float) $stored[$key] : $default;

    return [
        'latency_ms' => (int) $number('latency_ms', 5),
        'jitter_ms' => (int) $number('jitter_ms', 0),
        'fail_rate' => $number('fail_rate', 0),
        'fail_status' => (int) $number('fail_status', 503),
        'lose_response_rate' => $number('lose_response_rate', 0),
        'retry_after' => (int) $number('retry_after', 1),
        'throttle_until' => (int) $number('throttle_until', 0),
        'down_until' => (int) $number('down_until', 0),
    ];
}

function chance(float $rate): bool
{
    return $rate > 0 && mt_rand() / mt_getrandmax() < $rate;
}

function count_(Redis $redis, string $channel, string $outcome): void
{
    $redis->hIncrBy('mock:counters', "{$channel}|{$outcome}", 1);
}

/**
 * The provider endpoint.
 */
function send(Redis $redis): void
{
    $arrivedAt = nowMicros();
    $payload = json_decode((string) file_get_contents('php://input'), true);

    $to = is_array($payload) ? ($payload['to'] ?? null) : null;
    $channel = is_array($payload) ? ($payload['channel'] ?? null) : null;
    $content = is_array($payload) ? ($payload['content'] ?? null) : null;

    if (! is_string($to) || $to === '' || ! is_string($content) || $content === '' || ! in_array($channel, CHANNELS, true)) {
        respond(400, ['error' => 'Expected {"to": string, "channel": "sms"|"email"|"push", "content": string}.']);
    }

    // Every request that reaches the provider counts towards its rate,
    // whatever happens to it afterwards.
    $redis->rPush("mock:arrivals:{$channel}", (string) $arrivedAt);
    $redis->lTrim("mock:arrivals:{$channel}", -MAX_ARRIVALS_KEPT, -1);

    $settings = settings($redis);

    if ($arrivedAt < $settings['down_until']) {
        count_($redis, $channel, 'failed');
        respond(503, ['error' => 'provider unavailable (simulated outage)']);
    }

    if ($arrivedAt < $settings['throttle_until']) {
        count_($redis, $channel, 'throttled');
        respond(429, ['error' => 'rate limit exceeded (simulated)'], ['Retry-After' => (string) $settings['retry_after']]);
    }

    if (chance($settings['fail_rate'])) {
        count_($redis, $channel, 'failed');
        respond($settings['fail_status'], ['error' => 'temporary failure (simulated)']);
    }

    // From here on the message is delivered. A real provider would hand it
    // to the carrier now; the mock remembers it under its idempotency key.
    $messageId = uuid();
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $replayed = false;

    if (is_string($key) && $key !== '') {
        $first = $redis->set("mock:idempotency:{$key}", $messageId, ['nx', 'ex' => 86_400]);

        if ($first !== true) {
            // Seen before: do not deliver again, answer as the first time.
            $original = $redis->get("mock:idempotency:{$key}");
            $messageId = is_string($original) ? $original : $messageId;
            $replayed = true;
        }
    }

    count_($redis, $channel, $replayed ? 'replayed' : 'accepted');

    $delayMs = $settings['latency_ms'] + ($settings['jitter_ms'] > 0 ? mt_rand(0, $settings['jitter_ms']) : 0);
    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }

    if (chance($settings['lose_response_rate'])) {
        // Delivered, but the caller never learns it.
        count_($redis, $channel, 'lost');
        respond(504, ['error' => 'response lost (simulated): the message was accepted']);
    }

    respond(202, ['messageId' => $messageId, 'status' => 'accepted', 'timestamp' => isoTime(nowMicros())]);
}

/**
 * Largest number of arrivals in any one-second window [t, t + 1 s).
 *
 * @param  list<int>  $arrivals  microsecond timestamps, ascending
 */
function maxPerSecond(array $arrivals): int
{
    $max = 0;
    $start = 0;

    foreach ($arrivals as $end => $arrival) {
        while ($arrival - $arrivals[$start] >= 1_000_000) {
            $start++;
        }
        $max = max($max, $end - $start + 1);
    }

    return $max;
}

/**
 * @return array<string, array<string, int>>
 */
function counters(Redis $redis): array
{
    $stored = $redis->hGetAll('mock:counters');
    $stored = is_array($stored) ? $stored : [];
    $counters = [];

    foreach (CHANNELS as $channel) {
        foreach (OUTCOMES as $outcome) {
            $counters[$channel][$outcome] = (int) ($stored["{$channel}|{$outcome}"] ?? 0);
        }
    }

    return $counters;
}

function stats(Redis $redis): void
{
    $counters = counters($redis);
    $channels = [];
    $totals = array_fill_keys(OUTCOMES, 0);
    $requests = 0;

    foreach (CHANNELS as $channel) {
        $raw = $redis->lRange("mock:arrivals:{$channel}", 0, -1);
        $arrivals = array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            is_array($raw) ? array_values($raw) : [],
        );
        sort($arrivals);

        $count = count($arrivals);
        $first = $arrivals[0] ?? null;
        $last = $arrivals[$count - 1] ?? null;
        $span = $first !== null && $last !== null ? ($last - $first) / 1_000_000 : 0.0;

        $channels[$channel] = [
            'requests' => $count,
            // The number that proves or disproves the rate limit.
            'max_in_any_second' => maxPerSecond($arrivals),
            'average_per_second' => $span > 0 ? round($count / $span, 1) : null,
            'first_at' => $first === null ? null : isoTime($first),
            'last_at' => $last === null ? null : isoTime($last),
            'duration_seconds' => round($span, 2),
        ] + $counters[$channel];

        $requests += $count;

        foreach (OUTCOMES as $outcome) {
            $totals[$outcome] += $counters[$channel][$outcome];
        }
    }

    respond(200, [
        'requests' => $requests,
        // Messages actually delivered: unique idempotency keys accepted.
        'delivered' => $totals['accepted'],
        // Repeats that were recognised and not delivered a second time.
        'duplicates_suppressed' => $totals['replayed'],
        'failed' => $totals['failed'],
        'throttled' => $totals['throttled'],
        'responses_lost' => $totals['lost'],
        'channels' => $channels,
        'settings' => settings($redis),
    ]);
}

/**
 * Change fault injection. Durations are given in seconds from now:
 *
 *   {"latency_ms": 20, "jitter_ms": 10, "fail_rate": 0.3, "fail_status": 503,
 *    "lose_response_rate": 0.1, "throttle_seconds": 5, "retry_after": 2,
 *    "down_seconds": 10}
 */
function configure(Redis $redis): void
{
    $given = json_decode((string) file_get_contents('php://input'), true);

    if (! is_array($given)) {
        respond(400, ['error' => 'Expected a JSON object.']);
    }

    foreach (['latency_ms', 'jitter_ms', 'fail_rate', 'fail_status', 'lose_response_rate', 'retry_after'] as $key) {
        if (is_numeric($given[$key] ?? null)) {
            $redis->hSet('mock:config', $key, (string) $given[$key]);
        }
    }

    foreach (['throttle_seconds' => 'throttle_until', 'down_seconds' => 'down_until'] as $input => $stored) {
        if (is_numeric($given[$input] ?? null)) {
            $redis->hSet('mock:config', $stored, (string) (nowMicros() + (int) ((float) $given[$input] * 1_000_000)));
        }
    }

    respond(200, ['settings' => settings($redis)]);
}

function reset_(Redis $redis): void
{
    $redis->flushDB();

    respond(200, ['status' => 'reset']);
}

function prometheus(Redis $redis): void
{
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

    echo "# HELP mock_provider_requests_total Requests received by the mock provider.\n";
    echo "# TYPE mock_provider_requests_total counter\n";

    foreach (counters($redis) as $channel => $outcomes) {
        foreach ($outcomes as $outcome => $count) {
            echo "mock_provider_requests_total{channel=\"{$channel}\",outcome=\"{$outcome}\"} {$count}\n";
        }
    }

    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (is_string($uri) ? parse_url($uri, PHP_URL_PATH) : null) ?: '/';

try {
    $redis = connect();
} catch (Throwable $e) {
    respond(503, ['error' => 'mock provider cannot reach Redis: '.$e->getMessage()]);
}

match (true) {
    $path === '/__admin/health' => respond(200, ['status' => 'ok']),
    $path === '/__admin/stats' && $method === 'GET' => stats($redis),
    $path === '/__admin/config' && $method === 'POST' => configure($redis),
    $path === '/__admin/reset' && $method === 'POST' => reset_($redis),
    $path === '/metrics' && $method === 'GET' => prometheus($redis),
    // Any other POST is a send, so a webhook.site style path works too.
    $method === 'POST' => send($redis),
    default => respond(404, ['error' => 'Not found. POST a notification to /send.']),
};
