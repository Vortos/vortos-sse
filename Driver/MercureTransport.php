<?php

declare(strict_types=1);

namespace Vortos\Sse\Driver;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Vortos\Sse\Contract\HubSubscription;
use Vortos\Sse\Contract\RealtimeTransportInterface;
use Vortos\Sse\Hub\HubPublisherInterface;
use Vortos\Sse\Hub\MercureTokenMinter;

/**
 * Delivers nudges through a Mercure hub, so a live connection costs a goroutine in the hub instead of
 * a PHP worker thread in the application.
 *
 * ## What actually changes
 *
 * The in-process alternative ({@see \Vortos\Sse\Http\SseStream}) inverts the cost model: PHP holds the
 * connection and polls for changes, so the expensive resource (a worker thread) is consumed in
 * proportion to *idle* clients, and the cheap event (a change) is discovered late, on the next poll
 * tick. Here it is the other way round — publishing is an outbound POST that returns immediately, and
 * the connection is held by the hub, which is built for exactly that. Change latency drops from "up to
 * one poll interval" to "as fast as the POST", and idle clients stop costing anything the application
 * needs.
 *
 * ## Publishing never breaks the write
 *
 * `publish()` swallows every failure. This is not defensive habit, it is the contract: the publish sits
 * on the request path of the thing being announced, so a hub that is down or slow must not fail a
 * notification write, a permission change, or a checkout. The client's refetch-on-connect is the
 * backstop, and it is authoritative — which is precisely why losing a nudge is survivable and losing
 * the write would not be.
 *
 * A repeated failure is logged at most once a minute. A hub outage otherwise writes one warning per
 * publish, which at any real volume buries the incident it is reporting. The throttle is deliberately
 * time-bounded rather than once-per-process: these run inside long-lived FrankenPHP workers that
 * survive for days, so "once per process" would mean one warning ever, and a second, unrelated outage
 * a week later would be completely silent.
 *
 * ## Topic scoping
 *
 * The channel name is used verbatim as the topic, and each subscriber token names exactly that one
 * topic — never a prefix and never a wildcard (enforced in {@see MercureTokenMinter}). Channel names
 * therefore carry a real security weight: a channel shared between subjects becomes a topic shared
 * between subjects, and the hub will deliver it faithfully to everyone holding a token for it. Callers
 * must keep channels per-subject.
 */
final class MercureTransport implements RealtimeTransportInterface
{
    private const DEGRADED_LOG_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly HubPublisherInterface $publisher,
        private readonly MercureTokenMinter $minter,
        private readonly LoggerInterface $logger,
        /** Public hub URL the browser connects to — reachable from the client, unlike the internal one. */
        private readonly string $publicHubUrl,
        /** Cookie name the hub reads the subscriber credential from. */
        private readonly string $cookieName = 'mercureAuthorization',
        private readonly int $subscriberTtlSeconds = 3600,
        /** Emit the credential cookie as Secure. Disabled only for plain-HTTP local development. */
        private readonly bool $secureCookie = true,
    ) {}

    public function publish(string $channel, array $payload): void
    {
        try {
            $this->publisher->publish($channel, $payload, $this->minter->forPublisher());
        } catch (\Throwable $e) {
            $this->reportDegraded($e);
        }
    }

    /**
     * Narrower than the interface on purpose: a configured hub always has a subscription to offer, so
     * callers holding a concrete MercureTransport get that guarantee from the type rather than having to
     * null-check a branch that cannot happen.
     */
    public function subscription(string $channel): HubSubscription
    {
        // Deliberately not wrapped in a try/catch: a scoping or signing failure must surface, not
        // degrade. Falling back to the in-process stream on a *credential* error would mask a
        // misconfiguration that the operator needs to see, and silently move production load back onto
        // the worker pool this whole transport exists to protect.
        $token = $this->minter->forSubscriber([$channel], $this->subscriberTtlSeconds);

        $cookie = Cookie::create($this->cookieName)
            ->withValue($token)
            ->withExpires(time() + $this->subscriberTtlSeconds)
            // Scoped to the hub path: this credential has exactly one use, so there is no reason for it
            // to ride along on every other API request.
            ->withPath($this->hubPath())
            // Unreadable to page scripts, so an XSS cannot exfiltrate a live subscribe capability.
            ->withHttpOnly(true)
            ->withSecure($this->secureCookie)
            // Lax, not None: the SPA and the API are the same site, so Lax is sent on the subscription
            // request while still refusing genuinely cross-site use. See HubSubscription.
            ->withSameSite(Cookie::SAMESITE_LAX);

        return new HubSubscription(
            url: $this->publicHubUrl . '?topic=' . rawurlencode($channel),
            credential: $cookie,
            expiresInSeconds: $this->subscriberTtlSeconds,
        );
    }

    /**
     * The path component of the public hub URL, so the credential cookie is scoped to the hub rather
     * than to the whole origin. Falls back to '/' only if the URL has no parsable path, which would
     * mean a misconfigured hub URL — over-scoping the cookie is the lesser problem at that point.
     */
    private function hubPath(): string
    {
        $path = parse_url($this->publicHubUrl, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * Cross-request state, held on purpose. This object lives for the lifetime of a FrankenPHP worker
     * thread, which is what makes the throttle work at all — but it is also why the throttle must be a
     * timestamp and not a boolean. See the class docblock.
     */
    private int $lastReportedAt = 0;

    private function reportDegraded(\Throwable $e): void
    {
        $now = time();

        if ($now - $this->lastReportedAt < self::DEGRADED_LOG_INTERVAL_SECONDS) {
            return;
        }

        $this->lastReportedAt = $now;
        $this->logger->warning(
            'Mercure publish failed; live updates degraded to the client refetch until the hub recovers.',
            ['exception' => $e->getMessage()],
        );
    }
}
