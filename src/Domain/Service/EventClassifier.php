<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Service;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;

/**
 * @internal
 */
final readonly class EventClassifier
{
    /**
     * @return EventResult|null null when the observation is not eligible for the objective
     */
    public function classify(SloDefinition $slo, Objective $objective, Observation $observation): ?EventResult
    {
        $classification = $slo->classification();
        if (!$this->isEligible($classification, $observation)) {
            return null;
        }

        $bad = $this->isBad($classification, $observation);

        return match ($objective->kind) {
            ObjectiveKind::Availability => $bad ? EventResult::Bad : EventResult::Good,
            ObjectiveKind::Latency => $this->latency($objective, $observation, $bad),
            ObjectiveKind::Combined => $this->combined($objective, $observation, $bad),
        };
    }

    private function latency(Objective $objective, Observation $observation, bool $bad): ?EventResult
    {
        if ($bad && !$objective->includeErrors) {
            return null;
        }

        return $this->withinThreshold($objective, $observation);
    }

    private function combined(Objective $objective, Observation $observation, bool $bad): ?EventResult
    {
        if ($bad) {
            return EventResult::Bad;
        }

        return $this->withinThreshold($objective, $observation);
    }

    private function withinThreshold(Objective $objective, Observation $observation): ?EventResult
    {
        if ($observation->durationSeconds === null || !$objective->threshold instanceof LatencyThreshold) {
            return null;
        }

        return $observation->durationSeconds <= $objective->threshold->seconds() ? EventResult::Good : EventResult::Bad;
    }

    private function isEligible(Classification $classification, Observation $observation): bool
    {
        if ($observation->httpStatus !== null) {
            return !$classification->ineligibleStatuses->contains($observation->httpStatus);
        }

        if ($observation->deliveryStatus instanceof DeliveryStatus) {
            return !$observation->onFailureTransport
                && ($classification->countAttempts || $observation->deliveryStatus !== DeliveryStatus::Retried);
        }

        return true;
    }

    private function isBad(Classification $classification, Observation $observation): bool
    {
        if ($observation->httpStatus !== null) {
            return $classification->badStatuses->contains($observation->httpStatus);
        }

        if ($observation->deliveryStatus instanceof DeliveryStatus) {
            return $observation->deliveryStatus === DeliveryStatus::Failed
                || ($classification->countAttempts && $observation->deliveryStatus === DeliveryStatus::Retried);
        }

        return $observation->outcome === Outcome::Error
            || ($observation->outcome === Outcome::ExpectedFailure && !$classification->expectedFailureIsGood);
    }
}
