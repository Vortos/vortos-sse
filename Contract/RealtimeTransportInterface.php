<?php

declare(strict_types=1);

namespace Vortos\Sse\Contract;

/**
 * How a change nudge reaches a connected browser.
 *
 * ## Why this seam exists
 *
 * The original design had exactly one answer: hold a PHP request open and poll Redis inside it
 * ({@see \Vortos\Sse\Http\SseStream}). That works, and it is wrong at any scale, because a PHP worker
 * thread is a request-processing resource and an idle connection is not a request. With a fixed
 * worker pool, N open browser tabs remove N threads from circulation for as long as they stay open —
 * so the (N+1)th visitor does not get slower service, they queue behind a sleeping thread. The
 * failure is a cliff, not a curve, and it arrives at a user count equal to the worker count.
 *
 * The fix is not to tune the loop. It is to stop terminating long-lived connections in PHP at all:
 * publish the nudge to a hub that holds connections as goroutines, and let PHP go back to answering
 * requests. This interface is the seam that makes that swap invisible to application code — an app
 * publishes, and asks how a client should subscribe. It never learns which transport answered.
 *
 * ## Fail-safe contract
 *
 * Implementations MUST be fail-safe on publish, for the same reason
 * {@see RealtimeSignalInterface} is: a nudge is an optimisation over the client's refetch, never a
 * correctness dependency. An unreachable hub must degrade to "the client finds out on its next
 * refetch", never to a failed write of the thing being announced. Publishing happens on the request
 * path of whatever just changed, so a hung hub must not become a hung checkout.
 *
 * Subscription is different and deliberately so: minting a subscriber credential is a security
 * operation, and a transport that cannot mint one correctly must throw rather than hand out a token
 * with the wrong scope.
 */
interface RealtimeTransportInterface
{
    /**
     * Announce that a channel changed. Best-effort and non-throwing.
     *
     * The payload is a nudge, not the data: it exists so a client can tell *what kind* of change
     * happened without a round trip, and clients must still refetch to get authoritative state. Keep
     * it small and free of anything the recipient is not already entitled to read — with a hub
     * transport this crosses a process boundary and is retained in memory there.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(string $channel, array $payload): void;

    /**
     * How a client should subscribe to this channel, or null when this transport has no external hub
     * and the caller should fall back to serving a stream itself.
     *
     * Returning null is the explicit, supported degraded path — it is what keeps local development and
     * a hub-less deployment working — but it hands the connection back to the PHP worker pool, so it
     * is a fallback and never a destination.
     *
     * @throws \RuntimeException when a hub is configured but a correctly scoped credential cannot be
     *                           minted; never silently returns an over-scoped or unsigned token
     */
    public function subscription(string $channel): ?HubSubscription;
}
