<?php

declare(strict_types=1);

namespace Vortos\Sse\Driver;

use Psr\Log\LoggerInterface;
use Vortos\Sse\Contract\RealtimeSignalInterface;

/**
 * Redis-backed per-channel version counter (phpredis).
 *
 * Entirely fail-safe: a missing ext-redis, an unreachable server, or any error
 * is swallowed — signal() becomes a no-op and version() returns 0, degrading
 * live updates to the client's polling backstop rather than ever breaking. The
 * connection is established lazily and cached for the process lifetime.
 */
final class RedisRealtimeSignal implements RealtimeSignalInterface
{
    private const KEY_TTL_SECONDS = 86400;

    private ?\Redis $redis = null;
    private bool $unavailable = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $dsn = 'redis://127.0.0.1:6379',
        private readonly string $prefix = '',
    ) {}

    public function signal(string $channel): void
    {
        $redis = $this->connection();
        if ($redis === null) {
            return;
        }

        try {
            $key = $this->key($channel);
            $redis->incr($key);
            $redis->expire($key, self::KEY_TTL_SECONDS);
        } catch (\Throwable $e) {
            $this->markUnavailable($e);
        }
    }

    public function version(string $channel): int
    {
        $redis = $this->connection();
        if ($redis === null) {
            return 0;
        }

        try {
            $value = $redis->get($this->key($channel));

            return is_numeric($value) ? (int) $value : 0;
        } catch (\Throwable $e) {
            $this->markUnavailable($e);

            return 0;
        }
    }

    private function connection(): ?\Redis
    {
        if ($this->unavailable) {
            return null;
        }
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        try {
            if (!class_exists(\Redis::class)) {
                $this->unavailable = true;

                return null;
            }

            $parts = parse_url($this->dsn);
            $host  = $parts['host'] ?? '127.0.0.1';
            $port  = isset($parts['port']) ? (int) $parts['port'] : 6379;

            $redis = new \Redis();
            $redis->connect($host, $port, 1.5);
            if (isset($parts['pass']) && $parts['pass'] !== '') {
                $redis->auth($parts['pass']);
            }

            return $this->redis = $redis;
        } catch (\Throwable $e) {
            $this->markUnavailable($e);

            return null;
        }
    }

    private function markUnavailable(\Throwable $e): void
    {
        $this->unavailable = true;
        $this->redis = null;
        $this->logger->warning('Realtime signal unavailable; degrading to client polling.', [
            'exception' => $e->getMessage(),
        ]);
    }

    private function key(string $channel): string
    {
        return $this->prefix . 'sse:ver:' . $channel;
    }
}
