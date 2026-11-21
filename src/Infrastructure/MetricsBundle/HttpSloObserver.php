<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurement;
use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurementObserverInterface;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action as RecordEvent;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RoutePointIndexInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The duration is the one metrics-bundle measured; no second timer. Without HTTP SLOs the routes are never read.
 *
 * @internal
 */
final readonly class HttpSloObserver implements HttpMeasurementObserverInterface
{
    public function __construct(
        private RoutePointIndexInterface $index,
        private CatalogDiagnosticsInterface $diagnostics,
        private RecordEvent $record,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function onHttpMeasured(HttpMeasurement $measurement): void
    {
        if ($measurement->route === null) {
            return;
        }

        try {
            if (!$this->diagnostics->hasHttpPoints()) {
                return;
            }
            $points = $this->index->pointsFor($measurement->route);
            if ($points === []) {
                return;
            }
            $observation = Observation::http($measurement->status, $measurement->durationSeconds);
            foreach ($points as $point) {
                ($this->record)(new Input($point, $observation));
            }
        } catch (Throwable $e) {
            $this->logger->warning('SLO event was not recorded.', ['route' => $measurement->route, 'exception' => $e]);
        }
    }
}
