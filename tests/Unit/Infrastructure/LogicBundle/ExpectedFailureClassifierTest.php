<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\LogicBundle;

use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\LogicBundleExpectedFailureClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\NullExpectedFailureClassifier;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Operation\ExpectedFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExpectedFailureClassifierTest extends TestCase
{
    public function testLogicBundleClassifierFollowsLogicBundle(): void
    {
        $classifier = new LogicBundleExpectedFailureClassifier();

        self::assertTrue(LogicBundleExpectedFailureClassifier::isAvailable());
        self::assertTrue($classifier->isExpectedFailure(new ExpectedFailure('declined')));
        self::assertFalse($classifier->isExpectedFailure(new RuntimeException('boom')));
    }

    public function testNullClassifierCountsEveryExceptionAsAnError(): void
    {
        self::assertFalse((new NullExpectedFailureClassifier())->isExpectedFailure(new ExpectedFailure('declined')));
    }
}
