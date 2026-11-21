<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\LogicBundle;

use Msstc4Symfony\LogicBundle\Application\Action\ActionOutcome;
use Msstc4Symfony\SloBundle\Application\Operation\OperationMonitor;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ActionOutcomeClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ExpectedFailureInterfaceClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\NullExpectedFailureClassifier;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Operation\ExpectedFailure;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\Reference;

final class ExpectedFailureClassifierTest extends TestCase
{
    #[RequiresMethod(ActionOutcome::class, 'of')]
    public function testActionOutcomeClassifierFollowsLogicBundle(): void
    {
        $classifier = new ActionOutcomeClassifier();

        self::assertTrue($classifier->isExpectedFailure(new ExpectedFailure('declined')));
        self::assertFalse($classifier->isExpectedFailure(new RuntimeException('boom')));
    }

    #[RequiresMethod(ActionOutcome::class, 'of')]
    public function testLegacyClassifierRecognisesTheExpectedFailureInterface(): void
    {
        $classifier = new ExpectedFailureInterfaceClassifier();

        self::assertTrue($classifier->isExpectedFailure(new ExpectedFailure('declined')));
        self::assertFalse($classifier->isExpectedFailure(new RuntimeException('boom')));
    }

    #[RequiresMethod(ActionOutcome::class, 'of')]
    public function testNullClassifierCountsEveryExceptionAsAnError(): void
    {
        self::assertFalse((new NullExpectedFailureClassifier())->isExpectedFailure(new ExpectedFailure('declined')));
        self::assertFalse((new NullExpectedFailureClassifier())->isExpectedFailure(new RuntimeException('boom')));
    }

    #[RequiresMethod(ActionOutcome::class, 'of')]
    public function testTheContainerAliasesThePortToTheInstalledLogicBundle(): void
    {
        $classifier = TestContainer::compile()->getDefinition(OperationMonitor::class)->getArgument(3);

        self::assertInstanceOf(Reference::class, $classifier);
        self::assertSame(ActionOutcomeClassifier::class, (string) $classifier);
    }
}
