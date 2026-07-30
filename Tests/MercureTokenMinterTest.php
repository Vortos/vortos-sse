<?php

declare(strict_types=1);

namespace Vortos\Sse\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Sse\Hub\MercureTokenMinter;

/**
 * These are the tests that matter most in this package. A scoping mistake here does not crash — the hub
 * faithfully streams one subject's data to another subject's browser, and nothing on either side logs
 * anything unusual.
 *
 * @see MercureTokenMinter
 */
final class MercureTokenMinterTest extends TestCase
{
    private const SECRET = 'a-secret-of-at-least-thirty-two-characters';

    private static function minter(): MercureTokenMinter
    {
        return new MercureTokenMinter(self::SECRET);
    }

    /** @return array<string, mixed> */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'a JWT must have three dot-separated segments');

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($json);

        $claims = json_decode($json, true);
        self::assertIsArray($claims);

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    public function test_subscriber_token_is_scoped_to_exactly_the_requested_topic(): void
    {
        $claims = self::claims(self::minter()->forSubscriber(['notif:user:alice'], 3600));

        $this->assertSame(['notif:user:alice'], $claims['mercure']['subscribe']);
        // A subscriber must never also be granted publish rights.
        $this->assertArrayNotHasKey('publish', $claims['mercure']);
    }

    /**
     * The headline case. A wildcard subscriber receives every topic on the hub, which across tenants is
     * a cross-tenant leak performed correctly and invisibly by the hub.
     */
    public function test_refuses_to_mint_a_wildcard_subscriber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/wildcard/i');

        self::minter()->forSubscriber(['*'], 3600);
    }

    public function test_refuses_a_wildcard_hidden_among_valid_topics(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::minter()->forSubscriber(['notif:user:alice', '*'], 3600);
    }

    public function test_refuses_an_empty_topic(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::minter()->forSubscriber([''], 3600);
    }

    /** A token with no topics authorises nothing and would present as a silently dead subscription. */
    public function test_refuses_a_subscriber_with_no_topics(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::minter()->forSubscriber([], 3600);
    }

    public function test_refuses_a_non_positive_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::minter()->forSubscriber(['notif:user:alice'], 0);
    }

    /**
     * An empty secret does not disable signing — HMAC still produces a valid-looking signature, so the
     * hub would accept forged tokens for any topic. This must fail at construction, on boot.
     */
    public function test_refuses_an_empty_secret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least/');

        new MercureTokenMinter('');
    }

    public function test_refuses_a_short_secret(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MercureTokenMinter('too-short');
    }

    public function test_publisher_token_carries_publish_and_not_subscribe(): void
    {
        $claims = self::claims(self::minter()->forPublisher());

        $this->assertSame(['*'], $claims['mercure']['publish']);
        $this->assertArrayNotHasKey('subscribe', $claims['mercure']);
    }

    public function test_tokens_carry_an_expiry(): void
    {
        $claims = self::claims(self::minter()->forSubscriber(['notif:user:alice'], 120));

        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 120, $claims['exp']);
    }

    public function test_signature_is_hs256_over_header_and_payload(): void
    {
        $jwt = self::minter()->forSubscriber(['notif:user:alice'], 3600);
        [$header, $payload, $signature] = explode('.', $jwt);

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header . '.' . $payload, self::SECRET, true),
        ), '+/', '-_'), '=');

        $this->assertSame($expected, $signature);
    }

    /** A token signed with a different secret must not verify against ours. */
    public function test_signature_depends_on_the_secret(): void
    {
        $ours = self::minter()->forSubscriber(['notif:user:alice'], 3600);
        $theirs = (new MercureTokenMinter('a-completely-different-secret-32ch'))
            ->forSubscriber(['notif:user:alice'], 3600);

        $this->assertNotSame(
            explode('.', $ours)[2],
            explode('.', $theirs)[2],
        );
    }
}
