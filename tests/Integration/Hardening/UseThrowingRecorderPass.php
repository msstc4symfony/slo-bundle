<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Hardening;

use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsEventWriter;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsScheduledTaskRunWriter;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class UseThrowingRecorderPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition(MetricsEventWriter::class)->setArgument('$recorder', new Reference(ThrowingRecorder::class));
        $container->getDefinition(MetricsScheduledTaskRunWriter::class)->setArgument('$recorder', new Reference(ThrowingRecorder::class));
    }
}
