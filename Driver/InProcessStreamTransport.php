<?php

declare(strict_types=1);

namespace Vortos\Sse\Driver;

use Vortos\Sse\Contract\HubSubscription;
use Vortos\Sse\Contract\RealtimeSignalInterface;
use Vortos\Sse\Contract\RealtimeTransportInterface;

/**
 * The degraded transport: no hub, so the application serves the stream itself from a PHP worker.
 *
 * ## This is a fallback, not an alternative
 *
 * It exists so that local development, a test suite, and a deployment that has not configured a hub all
 * keep working — not because holding connections in PHP is a supported way to run. Every connection
 * served this way occupies a worker thread for the life of the stream, so concurrent clients are capped
 * at the worker count and the cap is a cliff: past it, unrelated requests queue behind a sleeping
 * thread rather than being served slowly. See {@see \Vortos\Sse\Http\SseStream} for the mechanics and
 * docs/plans/realtime-transport-mercure.md for the incident this was extracted from.
 *
 * `subscription()` returns null, which tells the caller "serve it yourself". That is the whole of the
 * signal — application code branches on whether a hub exists, never on which class it is talking to, so
 * configuring a hub is a deployment change rather than a code change.
 *
 * `publish()` bumps the channel version instead of pushing, because that is what an in-process stream
 * can observe: the stream loop polls the version and notices on its next tick. This is why change
 * latency under this transport is bounded by the poll interval rather than by the network.
 */
final class InProcessStreamTransport implements RealtimeTransportInterface
{
    public function __construct(private readonly RealtimeSignalInterface $signal) {}

    /**
     * The payload is discarded, and it has to be: there is nowhere to put it. An in-process stream
     * rebuilds the payload itself when it notices the version change (see `SseStream::watch()`), so the
     * data a caller passes here is redundant rather than lost. Callers must not depend on payload
     * contents reaching the client, which is already true — a nudge is never authoritative, and the
     * client refetches regardless.
     */
    public function publish(string $channel, array $payload): void
    {
        $this->signal->signal($channel);
    }

    public function subscription(string $channel): ?HubSubscription
    {
        return null;
    }
}
