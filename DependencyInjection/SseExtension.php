<?php

declare(strict_types=1);

namespace Vortos\Sse\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Sse\Contract\RealtimeSignalInterface;
use Vortos\Sse\Driver\RedisRealtimeSignal;
use Vortos\Sse\Http\SseStream;

/**
 * Wires the SSE services:
 *
 *   RealtimeSignalInterface — RedisRealtimeSignal, reading the shared cache DSN
 *                             and key prefix from the environment (fail-safe).
 *   SseStream               — the bounded SSE response helper.
 */
final class SseExtension extends Extension
{
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
    }
}
