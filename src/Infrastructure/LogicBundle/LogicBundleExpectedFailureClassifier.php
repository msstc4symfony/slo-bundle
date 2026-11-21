<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\LogicBundle;

use Msstc4Symfony\LogicBundle\Application\Action\ActionOutcome;
use Msstc4Symfony\LogicBundle\Application\Action\ExpectedFailureInterface;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Override;
use Throwable;

/**
 * Classifies as logic-bundle classifies its Actions (spec 5.3); logic-bundle releases before ActionOutcome only know
 * ExpectedFailureInterface.
 *
 * @internal
 */
final class LogicBundleExpectedFailureClassifier implements ExpectedFailureClassifierInterface
{
    public static function isAvailable(): bool
    {
        return enum_exists(ActionOutcome::class) || interface_exists(ExpectedFailureInterface::class);
    }

    #[Override]
    public function isExpectedFailure(Throwable $e): bool
    {
        if (enum_exists(ActionOutcome::class)) {
            return ActionOutcome::of($e) === ActionOutcome::ExpectedFailure;
        }

        return $e instanceof ExpectedFailureInterface;
    }
}
