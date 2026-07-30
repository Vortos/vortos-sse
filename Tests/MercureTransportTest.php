<?php

declare(strict_types=1);

namespace Vortos\Sse\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Vortos\Sse\Driver\MercureTransport;
use Vortos\Sse\Hub\HubPublisherInterface;
use Vortos\Sse\Hub\MercureTokenMinter;

/**
 * @see MercureTransport
 */
final class MercureTransportTest extends TestCase
{
    private const SECRET = 'a-secret-of-at-least-thirty-two-characters';
    private const PUBLIC_HUB = 'https://api.example.com/.well-known/mercure';

    private static function transport(HubPublisherInterface $publisher): MercureTransport
    {
        return new MercureTransport(
            publisher: $publisher,
            minter: new MercureTokenMinter(self::SECRET),
            logger: new NullLogger(),
            publicHubUrl: self::PUBLIC_HUB,
        );
    }

    private static function recordingPublisher(): HubPublisherInterface
    {
        return new class implements HubPublisherInterface {
            /** @var list<array{topic: string, data: array<string, mixed>, token: string}> */
            public array $published = [];

            public function publish(string $topic, array $data, string $bearerToken): void
            {
                $this->published[] = ['topic' => $topic, 'data' => $data, 'token' => $bearerToken];
            }
        };
    }

    private static function failingPublisher(): HubPublisherInterface
    {
        return new class implements HubPublisherInterface {
            public int $attempts = 0;

            public function publish(string $topic, array $data, string $bearerToken): void
            {
                ++$this->attempts;
                throw new \RuntimeException('hub unreachable');
            }
        };
    }

    public function test_publishes_the_channel_as_the_topic(): void
    {
        $publisher = self::recordingPublisher();
        self::transport($publisher)->publish('notif:user:alice', ['unreadCount' => 3]);

        $this->assertCount(1, $publisher->published);
        $this->assertSame('notif:user:alice', $publisher->published[0]['topic']);
        $this->assertSame(['unreadCount' => 3], $publisher->published[0]['data']);
    }

    /**
     * The whole fail-safe contract in one test. The publish sits on the request path of the thing being
     * announced, so a dead hub must never turn a successful notification write into a failed one.
     */
    public function test_a_failing_hub_does_not_throw(): void
    {
        $publisher = self::failingPublisher();

        self::transport($publisher)->publish('notif:user:alice', ['unreadCount' => 1]);

        $this->assertSame(1, $publisher->attempts, 'the publish should still have been attempted');
    }

    public function test_repeated_failures_keep_being_swallowed(): void
    {
        $publisher = self::failingPublisher();
        $transport = self::transport($publisher);

        for ($i = 0; $i < 5; ++$i) {
            $transport->publish('notif:user:alice', []);
        }

        $this->assertSame(5, $publisher->attempts);
    }

    public function test_subscription_url_carries_the_topic(): void
    {
        $subscription = self::transport(self::recordingPublisher())->subscription('notif:user:alice');

        $this->assertNotNull($subscription);
        $this->assertSame(self::PUBLIC_HUB . '?topic=notif%3Auser%3Aalice', $subscription->url);
    }

    public function test_credential_cookie_is_hardened(): void
    {
        $subscription = self::transport(self::recordingPublisher())->subscription('notif:user:alice');

        $this->assertNotNull($subscription);
        $cookie = $subscription->credential;

        // HttpOnly so an XSS cannot read a live subscribe capability out of the page.
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        // Lax, not None: the SPA and API are the same site, so Lax still reaches the hub.
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        // Scoped to the hub path so it is not attached to every other API request.
        $this->assertSame('/.well-known/mercure', $cookie->getPath());
    }

    /**
     * The credential must travel as a Set-Cookie and never in the body — serialising it would hand it
     * to page scripts and make HttpOnly pointless.
     */
    public function test_client_payload_excludes_the_credential(): void
    {
        $subscription = self::transport(self::recordingPublisher())->subscription('notif:user:alice');

        $this->assertNotNull($subscription);
        $encoded = json_encode($subscription->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($subscription->credential->getValue(), $encoded);
        $this->assertSame('hub', $subscription->toArray()['mode']);
    }

    /**
     * A scoping or signing failure must surface rather than silently degrade: falling back to
     * in-process streaming on a credential error would hide a misconfiguration and quietly move load
     * back onto the worker pool this transport exists to protect.
     */
    public function test_subscription_rejects_a_wildcard_channel_rather_than_degrading(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::transport(self::recordingPublisher())->subscription('*');
    }
}
