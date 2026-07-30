<?php

declare(strict_types=1);

namespace Vortos\Sse\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Sse\Contract\RealtimeSignalInterface;
use Vortos\Sse\Driver\InProcessStreamTransport;

/**
 * @see InProcessStreamTransport
 */
final class InProcessStreamTransportTest extends TestCase
{
    private static function signal(): RealtimeSignalInterface
    {
        return new class implements RealtimeSignalInterface {
            /** @var list<string> */
            public array $signalled = [];

            public function signal(string $channel): void
            {
                $this->signalled[] = $channel;
            }

            public function version(string $channel): int
            {
                return count($this->signalled);
            }
        };
    }

    public function test_publish_bumps_the_channel_version(): void
    {
        $signal = self::signal();
        (new InProcessStreamTransport($signal))->publish('notif:user:alice', ['unreadCount' => 2]);

        $this->assertSame(['notif:user:alice'], $signal->signalled);
    }

    /**
     * Returning null is the contract that tells the caller "serve the stream yourself". Application code
     * branches on whether a hub exists, never on which class answered — which is what makes configuring
     * a hub a deployment change rather than a code change.
     */
    public function test_no_hub_subscription_is_offered(): void
    {
        $this->assertNull(
            (new InProcessStreamTransport(self::signal()))->subscription('notif:user:alice'),
        );
    }
}
