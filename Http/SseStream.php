<?php

declare(strict_types=1);

namespace Vortos\Sse\Http;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Vortos\Sse\Contract\RealtimeSignalInterface;

/**
 * Builds a bounded Server-Sent Events response that watches a realtime channel
 * and emits a `ping` (carrying a caller-provided payload) whenever the channel's
 * version changes, with heartbeats in between.
 *
 * The connection is deliberately short-lived so no worker/thread is held
 * indefinitely — the browser's EventSource reconnects automatically. The ping is
 * a "refetch now" nudge, not the data itself, keeping it multi-tab safe.
 */
final class SseStream
{
    public function __construct(
        private readonly RealtimeSignalInterface $signal,
    ) {}

    /**
     * @param callable():array<string,mixed> $payloadProvider  builds the data emitted on each ping
     */
    public function watch(
        string $channel,
        callable $payloadProvider,
        int $lifetimeSeconds = 30,
        int $tickSeconds = 3,
    ): StreamedResponse {
        $signal = $this->signal;

        $response = new StreamedResponse(function () use ($signal, $channel, $payloadProvider, $lifetimeSeconds, $tickSeconds): void {
            @ignore_user_abort(false);
            @set_time_limit($lifetimeSeconds + 5);

            $lastVersion = $signal->version($channel);
            echo self::formatEvent('ping', $payloadProvider());
            self::flush();

            $deadline = time() + $lifetimeSeconds;
            while (time() < $deadline) {
                if (connection_aborted()) {
                    return;
                }

                $version = $signal->version($channel);
                if ($version !== $lastVersion) {
                    $lastVersion = $version;
                    echo self::formatEvent('ping', $payloadProvider());
                } else {
                    echo ": heartbeat\n\n";
                }
                self::flush();

                if ($tickSeconds <= 0) {
                    break;
                }
                sleep($tickSeconds);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function formatEvent(string $event, array $data): string
    {
        return 'event: ' . $event . "\n"
            . 'data: ' . json_encode($data, JSON_THROW_ON_ERROR) . "\n\n";
    }

    private static function flush(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}
