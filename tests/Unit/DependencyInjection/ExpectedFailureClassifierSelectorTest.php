<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\DependencyInjection;

use Msstc4Symfony\LogicBundle\Application\Action\ActionOutcome;
use Msstc4Symfony\LogicBundle\Application\Action\ExpectedFailureInterface;
use Msstc4Symfony\SloBundle\DependencyInjection\ExpectedFailureClassifierSelector;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ActionOutcomeClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ExpectedFailureInterfaceClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\NullExpectedFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
}
