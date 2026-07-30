<?php

declare(strict_types=1);

namespace Vortos\Sse\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Sse\Contract\RealtimeSignalInterface;
use Vortos\Sse\Contract\RealtimeTransportInterface;
use Vortos\Sse\Driver\InProcessStreamTransport;
use Vortos\Sse\Driver\MercureTransport;
use Vortos\Sse\Driver\RedisRealtimeSignal;
use Vortos\Sse\Hub\CurlHubPublisher;
use Vortos\Sse\Hub\MercureTokenMinter;
use Vortos\Sse\Http\SseStream;

/**
 * Wires the SSE services:
 *
 *   RealtimeSignalInterface    — RedisRealtimeSignal, reading the shared cache DSN and key prefix from
 *                                the environment (fail-safe).
 *   SseStream                  — the bounded in-PHP SSE response helper, used only on the degraded path.
 *   RealtimeTransportInterface — MercureTransport when a hub secret is configured, otherwise
 *                                InProcessStreamTransport.
 *
 * ## Why the transport is chosen here and not at runtime
 *
 * Selecting the driver in the container means application code carries no branch, and the choice is
 * visible in one place instead of being re-derived at every call site. It also means a half-configured
 * hub fails as a container that will not build, rather than as requests that behave differently
 * depending on which code path reached them first.
 *
 * ## Why the presence of a secret is the switch
 *
 * The hub cannot be used without a signing secret — every publish and every subscribe is authorised by a
 * JWT — so "is a secret configured" is the same question as "is a hub usable", with no way for the two to
 * disagree. It also fails in the safe direction: an environment that forgets the secret degrades to
 * in-process streaming (slow, but correct and rate-limited) instead of booting a transport that mints
 * forgeable tokens. {@see MercureTokenMinter} refuses a weak secret outright, so a placeholder cannot
 * quietly satisfy this check either.
 */
final class SseExtension extends Extension
{
    /**
     * Where the app publishes. Defaults to the loopback hub inside the same container: the hub ships in
     * the FrankenPHP binary, so in the normal deployment a publish never leaves the container. It must
     * not be pointed at a public URL — that would route every publish out through the edge and back.
     */
    private const DEFAULT_INTERNAL_HUB_URL = 'http://127.0.0.1:8080/.well-known/mercure';

    public function getAlias(): string
    {
        return 'vortos_sse';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Reuse the shared cache Redis DSN/prefix so no new config surface is added.
        $container->setParameter('env(VORTOS_CACHE_DSN)', 'redis://127.0.0.1:6379');
        $container->setParameter('env(VORTOS_CACHE_PREFIX)', '');

        $container->register(RedisRealtimeSignal::class, RedisRealtimeSignal::class)
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setArgument('$dsn', '%env(VORTOS_CACHE_DSN)%')
            ->setArgument('$prefix', '%env(VORTOS_CACHE_PREFIX)%')
            ->setPublic(false);

        $container->setAlias(RealtimeSignalInterface::class, RedisRealtimeSignal::class)
            ->setPublic(true);

        $container->register(SseStream::class, SseStream::class)
            ->setArgument('$signal', new Reference(RealtimeSignalInterface::class))
            ->setPublic(true);

        $this->registerTransport($container);
    }

    private function registerTransport(ContainerBuilder $container): void
    {
        $container->setParameter('env(VORTOS_MERCURE_JWT_SECRET)', '');
        $container->setParameter('env(VORTOS_MERCURE_INTERNAL_URL)', self::DEFAULT_INTERNAL_HUB_URL);
        $container->setParameter('env(VORTOS_MERCURE_PUBLIC_URL)', '');

        $container->register(InProcessStreamTransport::class, InProcessStreamTransport::class)
            ->setArgument('$signal', new Reference(RealtimeSignalInterface::class))
            ->setPublic(false);

        // Resolved at build time, not request time: the container is compiled per deploy, so which
        // transport is live is a deployment fact. Treating it as one keeps a single inspectable answer
        // per release instead of a decision that could vary between workers.
        $secret = (string) ($_SERVER['VORTOS_MERCURE_JWT_SECRET'] ?? $_ENV['VORTOS_MERCURE_JWT_SECRET'] ?? '');

        if ($secret === '') {
            // No hub configured. Degrade in the container graph rather than registering a Mercure
            // transport that would throw on first use.
            $container->setAlias(RealtimeTransportInterface::class, InProcessStreamTransport::class)
                ->setPublic(true);

            return;
        }

        $container->register(MercureTokenMinter::class, MercureTokenMinter::class)
            ->setArgument('$secret', '%env(VORTOS_MERCURE_JWT_SECRET)%')
            ->setPublic(false);

        $container->register(CurlHubPublisher::class, CurlHubPublisher::class)
            ->setArgument('$hubUrl', '%env(VORTOS_MERCURE_INTERNAL_URL)%')
            ->setPublic(false);

        $container->register(MercureTransport::class, MercureTransport::class)
            ->setArgument('$publisher', new Reference(CurlHubPublisher::class))
            ->setArgument('$minter', new Reference(MercureTokenMinter::class))
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setArgument('$publicHubUrl', '%env(VORTOS_MERCURE_PUBLIC_URL)%')
            ->setPublic(false);

        $container->setAlias(RealtimeTransportInterface::class, MercureTransport::class)
            ->setPublic(true);
    }
}
