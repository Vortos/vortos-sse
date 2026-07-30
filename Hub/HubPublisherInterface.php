<?php

declare(strict_types=1);

namespace Vortos\Sse\Hub;

/**
 * The single outbound call to the hub, behind an interface so the transport that decides *what* to
 * publish can be tested without a socket, and so the hub protocol can be swapped without touching
 * scoping or payload logic.
 *
 * Implementations MAY throw; {@see \Vortos\Sse\Driver\MercureTransport} is the layer responsible for
 * turning a failure into a degraded-but-working system rather than a failed write.
 */
interface HubPublisherInterface
{
    /**
     * Publish `$data` to `$topic`.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on any transport failure
     */
    public function publish(string $topic, array $data, string $bearerToken): void;
}
