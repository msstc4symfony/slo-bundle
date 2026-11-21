<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurementObserverInterface;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action as RecordEvent;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Only worker outcomes reach this observer: synchronously handled messages are not measured.
 *
 * @internal
 */
final readonly class MessengerSloObserver implements MessengerMeasurementObserverInterface
{
    public function __construct(
        private SloCatalogInterface $catalog,
        private CatalogDiagnosticsInterface $diagnostics,
        private DeliveryStatusMapper $statuses,
        private RecordEvent $record,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function onMessageMeasured(MessengerMeasurement $measurement): void
    {
        try {
            $point = PointId::of(PointType::MessengerMessage, $measurement->messageClass);
            if ($this->catalog->forPoint($point) === [] && $this->catalog->conflictedNamesFor($point) === []) {
                return;
            }
            $status = $this->statuses->map($measurement->status->value);
            if (!$status instanceof DeliveryStatus) {
                return;
            }
            $observation = Observation::delivery(
                $status,
                \in_array($measurement->transport, $this->diagnostics->failureTransports(), true),
                $measurement->durationSeconds,
            );
            ($this->record)(new Input($point, $observation));
        } catch (Throwable $e) {
            $this->logger->warning('SLO event was not recorded.', ['message' => $measurement->messageClass, 'transport' => $measurement->transport, 'exception' => $e]);
        }
    }
}
