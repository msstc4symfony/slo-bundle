<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Service;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;

final readonly class RevisionCalculator
{
    public function revision(SloDefinition $slo, ObjectiveKind $kind): Revision
    {
        $objective = $slo->objective($kind);
        if (!$objective instanceof Objective) {
            throw new InvalidArgumentException(\sprintf('SLO "%s" declares no "%s" objective.', $slo->name()->value, $kind->value));
        }
        $classification = $slo->classification();

        $input = [
            'bad_statuses' => $classification->badStatuses->canonical(),
            'count_attempts' => $classification->countAttempts,
            'expected_failure_is_good' => $classification->expectedFailureIsGood,
            'include_errors' => $objective->includeErrors,
            'ineligible_statuses' => $classification->ineligibleStatuses->canonical(),
            'kind' => $kind->value,
            'point_type' => $slo->pointType()->value,
            'threshold_ms' => $objective->threshold?->milliseconds,
        ];

        return Revision::fromHex(substr(hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 8));
    }
}
