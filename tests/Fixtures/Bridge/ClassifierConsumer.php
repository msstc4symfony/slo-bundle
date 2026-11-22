<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Bridge;

use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;

final readonly class ClassifierConsumer
{
    public function __construct(public ExpectedFailureClassifierInterface $classifier)
    {
    }
}
