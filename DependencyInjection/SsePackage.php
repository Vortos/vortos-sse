<?php

declare(strict_types=1);

namespace Vortos\Sse\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\Contract\PackageInterface;

/**
 * SSE package. Registers the realtime signal and the SSE stream helper (see
 * SseExtension). Must be registered after LoggerPackage in Container.php.
 */
final class SsePackage implements PackageInterface
{
    public function build(ContainerBuilder $container): void {}

    public function getContainerExtension(): SseExtension
    {
        return new SseExtension();
    }
}
