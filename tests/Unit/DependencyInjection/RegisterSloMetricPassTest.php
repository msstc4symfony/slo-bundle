<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\DependencyInjection;

use LogicException;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\RegisterSloMetricPass;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\SloMetric;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RegisterSloMetricPassTest extends TestCase
{
    public function testAppendsTheEnumOnceEvenWhenRunTwice(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('msstc4symfony_metrics.metric_enums', ['Other\Metric']);

        (new RegisterSloMetricPass())->process($container);
        (new RegisterSloMetricPass())->process($container);

        self::assertSame(['Other\Metric', SloMetric::class], $container->getParameter('msstc4symfony_metrics.metric_enums'));
    }

    public function testMissingMetricsBundleFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SloBundle needs MetricsBundle: register Msstc4Symfony\MetricsBundle\MetricsBundle in config/bundles.php.');

        (new RegisterSloMetricPass())->process(new ContainerBuilder());
    }

    public function testNonListParameterFails(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('msstc4symfony_metrics.metric_enums', 'nope');

        $this->expectException(LogicException::class);

        (new RegisterSloMetricPass())->process($container);
    }
}
