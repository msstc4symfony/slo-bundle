<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Binding;

use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use PHPUnit\Framework\TestCase;

final class ObjectiveKeyTest extends TestCase
{
    public function testJoinsTheSloNameAndTheObjectiveKind(): void
    {
        self::assertSame('payment.create|latency', ObjectiveKey::of(SloName::fromString('payment.create'), ObjectiveKind::Latency)->value);
    }
}
