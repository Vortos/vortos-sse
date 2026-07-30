<?php

declare(strict_types=1);

namespace Vortos\Sse\Hub;

use RuntimeException;

/**
 * Posts to the Mercure hub over curl, matching the framework's other outbound transports
 * (see Observability\Marker\CurlMarkerTransport, AnalyticsPosthog\CurlAnalyticsTransport).
 *
 * The hub speaks form-encoded `topic` + `data`, not JSON — the JSON is the *value* of the `data`
 * field. Sending a JSON body instead is accepted with a 400 that reads like a malformed payload, so
 * it is worth being explicit about.
 *
 * Timeouts are short on purpose. This call sits on the request path of whatever just changed — a
 * notification write, a permission change — and the hub is normally in the same container, so a
 * healthy publish is sub-millisecond. Anything slower means the hub is unhealthy, and in that case the
 * correct behaviour is to give up immediately and let the client find out on its next refetch. A
 * generous timeout here would convert a sick hub into a sick application.
 */
final class CurlHubPublisher implements HubPublisherInterface
{
    private const TIMEOUT_SECONDS = 2;
    private const CONNECT_TIMEOUT_SECONDS = 1;

    /**
     * Kept identical to the event name {@see \Vortos\Sse\Http\SseStream} emits, so a client written
     * against one transport works unchanged against the other.
     */
    public const EVENT_TYPE = 'ping';

    public function __construct(private readonly string $hubUrl) {}

    public function publish(string $topic, array $data, string $bearerToken): void
    {
        $ch = curl_init($this->hubUrl);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize curl handle for Mercure publish.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'topic' => $topic,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                // Names the SSE event type. Without it Mercure emits an unnamed `message` event, while
                // the in-process fallback emits `event: ping` — so the client would need two listeners
                // and would silently receive nothing on whichever transport it guessed wrong about.
                'type' => self::EVENT_TYPE,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Bearer ' . $bearerToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException(sprintf(
                'Mercure publish curl error (errno %d) posting to %s.',
                $errno,
                $this->hubUrl,
            ));
        }

        if ($status < 200 || $status >= 300) {
            // The body is included because the hub's rejections are specific and actionable — an
            // out-of-scope topic and an expired token are both 401s that look identical without it.
            throw new RuntimeException(sprintf(
                'Mercure hub rejected publish with HTTP %d: %s',
                $status,
                is_string($response) ? substr($response, 0, 200) : '(no body)',
            ));
        }
    }
}
