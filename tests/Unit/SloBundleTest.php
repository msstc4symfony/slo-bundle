<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit;

use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\CollectSloDefinitionsPass;
use Msstc4Symfony\SloBundle\SloBundle;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SloBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        self::assertSame('msstc4symfony_slo', (new SloBundle())->getContainerExtension()?->getAlias());
    }

    public function testDiscoveryRunsAfterPassesAtPriorityZero(): void
    {
        $container = new ContainerBuilder();
        (new SloBundle())->build($container);
        $later = new class implements CompilerPassInterface {
            #[Override]
            public function process(ContainerBuilder $container): void
            {
            }
        };
        $container->addCompilerPass($later, PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);

        $passes = $container->getCompilerPassConfig()->getBeforeOptimizationPasses();
        $discovery = array_keys(array_filter($passes, static fn (CompilerPassInterface $pass): bool => $pass instanceof CollectSloDefinitionsPass));

        self::assertCount(1, $discovery);
        self::assertGreaterThan(array_search($later, $passes, true), $discovery[0]);
    }
}
