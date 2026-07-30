<?php

declare(strict_types=1);

namespace Vortos\Sse\Contract;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Everything a browser needs to open a live subscription against an external hub: where to connect,
 * and the credential that authorises it for exactly one topic.
 *
 * ## Why the credential is a cookie and not part of the URL
 *
 * The browser API for this is `EventSource`, which cannot set request headers — so the token has to
 * travel either as a query parameter or as a cookie. A query parameter would put a signed credential
 * into every access log, proxy log, and error report that records a URL, where it outlives the
 * session it belonged to and is trivially replayable until expiry. A cookie keeps it out of the URL,
 * and lets it be marked `HttpOnly` so page scripts (including anything injected) cannot read it back.
 *
 * This works because the app and the API are the same *site* — different origins, same registrable
 * domain — so a `SameSite=Lax` cookie set on the API host is still sent on the subscription request.
 * That is a property of the current domain layout, not a general truth: splitting the SPA onto an
 * unrelated domain would break it and force `SameSite=None`, which is a decision that should be made
 * deliberately rather than discovered from a silently dead notification bell.
 *
 * The cookie is scoped to the hub path, not to `/`, so it is not attached to ordinary API calls. It
 * grants exactly one capability — subscribe to one topic — and there is no reason for it to be
 * visible on any other request.
 */
final readonly class HubSubscription
{
    /**
     * @param string $url             absolute URL the client opens with `EventSource`, already carrying
     *                                the topic it is authorised for
     * @param Cookie $credential      the scoped, HttpOnly subscriber credential; the caller must attach
     *                                this to the response, or the subscription is rejected by the hub
     * @param int    $expiresInSeconds lifetime of the credential, so a client can re-mint before it
     *                                lapses rather than discovering expiry as a failed reconnect
     */
    public function __construct(
        public string $url,
        public Cookie $credential,
        public int $expiresInSeconds,
    ) {
        if ($url === '') {
            throw new \InvalidArgumentException('HubSubscription.url must not be empty.');
        }

        if ($expiresInSeconds < 1) {
            throw new \InvalidArgumentException(sprintf(
                'HubSubscription.expiresInSeconds must be positive, got %d.',
                $expiresInSeconds,
            ));
        }
    }

    /**
     * The client-facing shape. Deliberately excludes the credential: it travels as a `Set-Cookie` and
     * must never be serialised into a response body, where page scripts could read it and the
     * `HttpOnly` protection would be pointless.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => 'hub',
            'url' => $this->url,
            'expiresInSeconds' => $this->expiresInSeconds,
        ];
    }
}
