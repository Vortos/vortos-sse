<?php

declare(strict_types=1);

namespace Vortos\Sse\Hub;

/**
 * Mints the HS256 JWTs the Mercure hub uses to authorise publishing and subscribing.
 *
 * ## The security property this class exists to hold
 *
 * A Mercure subscriber token is a capability: it names the topics its bearer may receive. Get the
 * scope wrong and the hub does exactly what it was told — streams one tenant's notifications to
 * another tenant's browser, server-side, with no audit trail on the reading side and nothing in the
 * application logs to suggest it happened. That makes topic scoping the single most consequential
 * decision in this package, which is why it lives in one small class with one job rather than being
 * assembled inline at a call site.
 *
 * Two rules are enforced here rather than trusted to callers:
 *
 *   1. **No wildcard subscribers.** Mercure treats `*` as "every topic". It is legitimate for a
 *      publisher (the app publishes to whatever changed) and never legitimate for a browser. A
 *      subscriber request containing a wildcard is rejected, so the catastrophic case cannot be
 *      reached by a typo or a refactor that widens a variable.
 *   2. **No unsigned tokens.** An empty or missing secret is refused rather than used. HMAC with an
 *      empty key still produces a valid-looking signature, so the failure mode of a missing secret is
 *      not "nothing works" — it is "everything works and anyone can forge a token for any topic". It
 *      must fail closed and loudly at boot instead.
 *
 * HS256 is the right algorithm here specifically because the hub is co-located with the app in the
 * same container: the secret never crosses a host boundary, so the key-distribution problem that
 * would justify asymmetric signing does not exist, and a shared secret avoids standing up a second
 * keyring beside the one vortos-auth already maintains for user tokens.
 */
final class MercureTokenMinter
{
    /** Mercure's "all topics" selector. Valid for a publisher, never for a browser. */
    public const WILDCARD_TOPIC = '*';

    /**
     * A secret shorter than this cannot carry 256 bits of entropy and is almost certainly a
     * placeholder that reached production. Refused at construction so it surfaces on boot rather
     * than as forged tokens later.
     */
    private const MIN_SECRET_LENGTH = 32;

    public function __construct(private readonly string $secret)
    {
        if (strlen($this->secret) < self::MIN_SECRET_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Mercure JWT secret must be at least %d characters, got %d. An empty or weak secret does '
                . 'not disable signing — HMAC still produces a valid-looking signature, so the hub would '
                . 'accept tokens anyone could forge for any topic. Set VORTOS_MERCURE_JWT_SECRET to a '
                . 'high-entropy value, or leave the hub unconfigured to fall back to in-process streaming.',
                self::MIN_SECRET_LENGTH,
                strlen($this->secret),
            ));
        }
    }

    /**
     * A token authorising a browser to subscribe to exactly the given topics.
     *
     * @param list<string> $topics
     *
     * @throws \InvalidArgumentException if empty, or if any topic is a wildcard
     */
    public function forSubscriber(array $topics, int $ttlSeconds): string
    {
        if ($topics === []) {
            throw new \InvalidArgumentException(
                'A subscriber token must name at least one topic; a token with no topics authorises '
                . 'nothing and would present as a silently dead subscription.',
            );
        }

        foreach ($topics as $topic) {
            if ($topic === '' || $topic === self::WILDCARD_TOPIC) {
                throw new \InvalidArgumentException(sprintf(
                    'Refusing to mint a subscriber token for topic "%s". A wildcard subscriber can receive '
                    . 'every topic on the hub, which across tenants is a cross-tenant data leak the hub '
                    . 'would perform faithfully and invisibly. Name the exact per-subject topic instead.',
                    $topic,
                ));
            }
        }

        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException(sprintf(
                'Subscriber token TTL must be positive, got %d.',
                $ttlSeconds,
            ));
        }

        return $this->sign([
            'mercure' => ['subscribe' => $topics],
            'exp' => time() + $ttlSeconds,
        ]);
    }

    /**
     * A token authorising the application to publish. Wildcard by default and deliberately: the app
     * publishes to whichever channel just changed, and enumerating those up front would mean a new
     * token per notification. This token never leaves the server.
     *
     * @param list<string> $topics
     */
    public function forPublisher(array $topics = [self::WILDCARD_TOPIC], int $ttlSeconds = 3600): string
    {
        if ($topics === []) {
            throw new \InvalidArgumentException('A publisher token must name at least one topic selector.');
        }

        return $this->sign([
            'mercure' => ['publish' => $topics],
            'exp' => time() + $ttlSeconds,
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function sign(array $claims): string
    {
        $header = self::base64Url((string) json_encode(
            ['alg' => 'HS256', 'typ' => 'JWT'],
            JSON_THROW_ON_ERROR,
        ));
        $payload = self::base64Url((string) json_encode($claims, JSON_THROW_ON_ERROR));

        $signature = self::base64Url(hash_hmac('sha256', $header . '.' . $payload, $this->secret, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
