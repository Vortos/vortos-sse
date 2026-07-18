<?php

declare(strict_types=1);

namespace Vortos\Sse\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vortos\Sse\Contract\RealtimeSignalInterface;
use Vortos\Sse\Http\SseStream;

final class SseStreamTest extends TestCase
{
    public function testFormatEventProducesAValidSseFrame(): void
    {
        $frame = SseStream::formatEvent('ping', ['unreadCount' => 3]);

        self::assertSame("event: ping\ndata: {\"unreadCount\":3}\n\n", $frame);
    }

    public function testWatchReturnsAStreamedEventStreamResponse(): void
    {
        $stream   = new SseStream($this->stubSignal());
        $response = $stream->watch('user:1', static fn (): array => ['unreadCount' => 0], lifetimeSeconds: 0, tickSeconds: 0);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    private function stubSignal(): RealtimeSignalInterface
    {
        return new class implements RealtimeSignalInterface {
            public function signal(string $channel): void {}

            public function version(string $channel): int
            {
                return 0;
            }
        };
    }
}
