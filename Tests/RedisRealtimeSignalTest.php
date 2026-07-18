<?php

declare(strict_types=1);

namespace Vortos\Sse\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Sse\Driver\RedisRealtimeSignal;

/**
 * The signal must degrade cleanly when Redis is unreachable — a connection
 * refused (or missing ext-redis) becomes version 0 and a no-op signal, never an
 * exception, so notification delivery is never coupled to Redis health.
 */
final class RedisRealtimeSignalTest extends TestCase
{
    public function testUnreachableRedisDegradesToZeroWithoutThrowing(): void
    {
        // Port 1 refuses immediately; if ext-redis is absent it short-circuits the same way.
        $signal = new RedisRealtimeSignal(new NullLogger(), 'redis://127.0.0.1:1', 'test_');

        $signal->signal('user:1'); // must not throw
        self::assertSame(0, $signal->version('user:1'));
    }
}
