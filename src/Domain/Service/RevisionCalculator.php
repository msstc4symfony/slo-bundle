<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Service;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;

/**
 * @internal
 */
final readonly class RevisionCalculator
{
    /**
     * @param list<non-empty-string> $failureTransports enter the revision of messenger_message SLOs only: changing them changes which deliveries are eligible
     */
    public function revision(SloDefinition $slo, ObjectiveKind $kind, array $failureTransports): Revision
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
        // Outcome-point latency covers every non-error run regardless of expectedFailureIsGood (spec 4.4).
        if ($kind === ObjectiveKind::Latency && \in_array($slo->pointType(), [PointType::LogicAction, PointType::Operation, PointType::ScheduledTask], true)) {
            unset($input['expected_failure_is_good']);
        }
        if ($slo->pointType() === PointType::MessengerMessage) {
            $transports = array_values(array_unique($failureTransports));
            sort($transports, SORT_STRING);
            $input['failure_transports'] = $transports;
        }

        return Revision::fromHex(substr(hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 8));
    }
}
