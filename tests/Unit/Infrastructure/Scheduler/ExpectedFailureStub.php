<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Scheduler;

use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Override;
use Throwable;

final class ExpectedFailureStub implements ExpectedFailureClassifierInterface
{
    /** @var list<Throwable> */
    public array $asked = [];

    public function __construct(private readonly ?Throwable $expected = null)
    {
    }

    #[Override]
    public function isExpectedFailure(Throwable $e): bool
    {
        $this->asked[] = $e;

        return $e === $this->expected;
    }
}
