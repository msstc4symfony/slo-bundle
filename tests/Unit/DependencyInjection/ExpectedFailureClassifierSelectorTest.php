<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\DependencyInjection;

use Msstc4Symfony\LogicBundle\Application\Action\ActionOutcome;
use Msstc4Symfony\LogicBundle\Application\Action\ExpectedFailureInterface;
use Msstc4Symfony\SloBundle\DependencyInjection\ExpectedFailureClassifierSelector;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ActionOutcomeClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ExpectedFailureInterfaceClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\NullExpectedFailureClassifier;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\ClassExistenceResource;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ExpectedFailureClassifierSelectorTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, class-string}>
     */
    public static function installedProvider(): iterable
    {
        yield 'logic-bundle with ActionOutcome' => [[ActionOutcome::class, ExpectedFailureInterface::class], ActionOutcomeClassifier::class];
        yield 'logic-bundle before ActionOutcome' => [[ExpectedFailureInterface::class], ExpectedFailureInterfaceClassifier::class];
        yield 'no logic-bundle' => [[], NullExpectedFailureClassifier::class];
    }

    /**
     * @param list<string> $installed
     * @param class-string $expected
     */
    #[DataProvider('installedProvider')]
    public function testPicksTheAdapterOfTheInstalledLogicBundle(array $installed, string $expected): void
    {
        $container = new ContainerBuilder();

        self::assertSame($expected, ExpectedFailureClassifierSelector::select($container, static fn (string $name): bool => \in_array($name, $installed, true)));
    }

    public function testTrackedResourcesRecordTheProbedExistenceSoTheyStayFresh(): void
    {
        $container = new class extends ContainerBuilder {
            /** @var list<ResourceInterface> */
            public array $added = [];

            #[Override]
            public function addResource(ResourceInterface $resource): static
            {
                $this->added[] = $resource;

                return $this;
            }
        };

        ExpectedFailureClassifierSelector::select($container);

        self::assertSame([ActionOutcome::class, ExpectedFailureInterface::class], array_map(strval(...), $container->added));
        foreach ($container->added as $resource) {
            self::assertInstanceOf(ClassExistenceResource::class, $resource);
            self::assertTrue($resource->isFresh(time()), $resource . ' exists (logic-bundle is a dev dependency) and must not trigger a rebuild');
        }
    }
}
