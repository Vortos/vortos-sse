<?php

declare(strict_types=1);

namespace Vortos\Sse\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Sse\Hub\CurlHubPublisher;

/**
 * Guards the wire format of a publish. These assertions look pedantic and are not: two of the three
 * fields below are load-bearing for correctness or security, and getting either wrong fails silently.
 *
 * The publisher is exercised against a local socket rather than mocked, because the thing under test
 * IS the encoded request body — a mock would only re-assert whatever this class chose to pass along.
 *
 * @see CurlHubPublisher
 */
final class CurlHubPublisherTest extends TestCase
{
    /**
     * Captures one request body by standing up a throwaway HTTP listener, so the assertions are made
     * against bytes that actually went over a socket.
     */
    private function capturePublishBody(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, "could not open a local listener: {$errstr}");

        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $url = 'http://' . $name . '/.well-known/mercure';

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child: publish, then exit without running the rest of the suite.
            fclose($server);
            try {
                (new CurlHubPublisher($url))->publish('notif:user:alice', ['authzVersion' => 7], 'tok');
            } catch (\Throwable) {
                // The listener replies 200 but the child's view of it does not matter here.
            }
            exit(0);
        }

        $conn = stream_socket_accept($server, 5);
        self::assertIsResource($conn, 'publisher never connected');

        stream_set_timeout($conn, 5);
        $raw = '';
        // Read headers, then exactly Content-Length bytes of body.
        while (!str_contains($raw, "\r\n\r\n")) {
            $chunk = fread($conn, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }

        [$headers, $body] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m) === 1) {
            $length = (int) $m[1];
            while (strlen($body) < $length) {
                $chunk = fread($conn, $length - strlen($body));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
        }

        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($conn);
        fclose($server);
        pcntl_waitpid($pid, $status);

        return $body;
    }

    /**
     * THE security assertion. Mercure enforces a subscriber's topic scope only for updates marked
     * private; a public update is delivered to any authenticated subscriber that names the topic,
     * whatever their token allows. Omitting this flag therefore makes topic scoping in the token minter
     * decorative, and lets any signed-in subscriber read any other subject's channel.
     *
     * This was not hypothetical: it shipped, and a live hub delivered topic B to a token scoped to
     * topic A until the flag was added.
     */
    public function test_publishes_as_a_private_update(): void
    {
        $body = $this->capturePublishBody();

        self::assertStringContainsString('private=on', $body);
    }

    /**
     * The event name must match what the in-process fallback emits, or a client written against one
     * transport listens for an event the other never sends — and receives nothing, silently.
     */
    public function test_names_the_ping_event_type(): void
    {
        self::assertStringContainsString('type=' . CurlHubPublisher::EVENT_TYPE, $this->capturePublishBody());
    }

    public function test_sends_the_topic_and_json_payload(): void
    {
        $body = $this->capturePublishBody();

        self::assertStringContainsString('topic=' . rawurlencode('notif:user:alice'), $body);
        parse_str($body, $fields);
        self::assertSame(['authzVersion' => 7], json_decode((string) ($fields['data'] ?? ''), true));
    }
}
