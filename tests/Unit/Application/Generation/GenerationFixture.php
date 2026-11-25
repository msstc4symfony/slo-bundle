<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Action as CoverageAction;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\HandlerInfo;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjectiveCollector;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateAlertmanager\Action as AlertmanagerAction;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Action as DashboardsAction;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Input as DashboardsInput;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Input;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Action as SlothAction;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Alertmanager\AlertmanagerFileBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\GrafanaFileBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\RuleGroupsBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Sloth\SlothSpecBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\AuditPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\ProcessPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;

/**
 * The catalog behind the golden files: HTTP availability (A) + latency 200ms (B), Messenger availability and
 * latency 1s (A), an operation (B) on a 3d window, two scheduled tasks (B): a service task with a 120s latency and a
 * command task with a 10m deadline.
 */
final class GenerationFixture
{
    public const CHECKOUT = 'App\Controller\CheckoutController::pay';

    public const CLEANUP_TASK = 'App\Task\CleanupTask::__invoke';

    public const SYNC_PARTNERS_TASK = 'command:app:sync-partners';

    public static function catalog(float $checkoutLatencyTarget = 99.0, float $checkoutAvailabilityTarget = 99.9, bool $logicHistogram = true): CompiledSloCatalog
    {
        return CatalogFixture::build(
            static function (CatalogAssembler $assembler) use ($checkoutLatencyTarget, $checkoutAvailabilityTarget): void {
                $assembler->declare(PointId::of(PointType::HttpRoute, self::CHECKOUT), new Slo('checkout.pay', [new Availability($checkoutAvailabilityTarget), new Latency('200ms', $checkoutLatencyTarget)]));
                $assembler->declare(PointId::of(PointType::MessengerMessage, 'App\Message\ProcessPayment'), new Slo('payment.process', [new Latency('1s', 99), new Availability(99.5)]));
                $assembler->declare(PointId::of(PointType::Operation, 'report.export'), new Slo('report.export', [new Availability(99)], window: '3d', strategy: StrategyPreference::Dedicated));
                $assembler->declare(PointId::of(PointType::ScheduledTask, self::CLEANUP_TASK), new Slo('cleanup.run', [new Availability(99), new Latency('120s', 95)]));
                $assembler->declare(PointId::of(PointType::ScheduledTask, self::SYNC_PARTNERS_TASK), new Slo('partners.sync', [new Availability(99.5)], deadline: '10m'));
            },
            new CatalogExtras(
                handlers: [
                    new HandlerInfo(ProcessPaymentHandler::class, 'App\Message\ProcessPayment', true, 'async'),
                    new HandlerInfo(AuditPaymentHandler::class, 'App\Message\AuditPayment', true, 'async'),
                ],
                failureTransports: ['failed'],
                metricBuckets: [
                    'request_duration_histogram_seconds' => [0.05, 0.1, 0.25, 0.5, 1.0],
                    'messenger_message_handling_duration_histogram_seconds' => [0.1, 0.5, 1.0, 5.0],
                    ...($logicHistogram ? ['logic_action_duration_histogram_seconds' => [0.1, 0.5, 1.0]] : []),
                ],
                scheduledTasks: [self::CLEANUP_TASK, self::SYNC_PARTNERS_TASK],
            ),
        );
    }

    /**
     * One scheduled-task SLO on a service task and a command task, on a 3d window so promtool can cover it.
     */
    public static function multiPointTaskCatalog(): CompiledSloCatalog
    {
        return CatalogFixture::build(
            static function (CatalogAssembler $assembler): void {
                $slo = new Slo('nightly.jobs', [new Availability(99)], window: '3d', deadline: '2m');
                $assembler->declare(PointId::of(PointType::ScheduledTask, 'App\Task\NightlyTask::__invoke'), $slo);
                $assembler->declare(PointId::of(PointType::ScheduledTask, 'command:app:nightly'), $slo);
            },
            new CatalogExtras(scheduledTasks: ['App\Task\NightlyTask::__invoke', 'command:app:nightly']),
        );
    }

    public static function routes(): InMemoryRouteCatalog
    {
        return new InMemoryRouteCatalog([
            new RouteInfo('checkout_pay', '/checkout/pay', ['POST'], self::CHECKOUT),
            new RouteInfo('health', '/health', ['GET'], 'App\Controller\HealthController::check'),
        ]);
    }

    public static function collector(CompiledSloCatalog $catalog): BoundObjectiveCollector
    {
        return new BoundObjectiveCollector($catalog, new MetricBindingResolver(self::routes(), $catalog, new MetricsBundleSeriesCatalog()), new RevisionResolver(new RevisionCalculator(), $catalog));
    }

    public static function action(CompiledSloCatalog $catalog): Action
    {
        return new Action(self::collector($catalog), new RuleGroupsBuilder(new PromQlBuilder(), new BurnRatePolicy(), new DeterministicYaml()), new BurnRatePolicy(), $catalog);
    }

    public static function slothAction(CompiledSloCatalog $catalog): SlothAction
    {
        return new SlothAction(self::collector($catalog), new SlothSpecBuilder(new PromQlBuilder(), new DeterministicYaml()));
    }

    public static function alertmanagerAction(): AlertmanagerAction
    {
        return new AlertmanagerAction(new AlertmanagerFileBuilder(new DeterministicYaml()));
    }

    /**
     * @param list<string> $ignoredRoutes
     */
    public static function dashboardsAction(CompiledSloCatalog $catalog, ?GrafanaFileBuilder $builder = null, array $ignoredRoutes = []): DashboardsAction
    {
        return new DashboardsAction(self::collector($catalog), new CoverageAction($catalog, $catalog, self::routes(), new MetricsBundleSeriesCatalog()), $builder ?? GrafanaFileBuilder::standard(), $catalog, new MetricsBundleSeriesCatalog(), $ignoredRoutes);
    }

    public static function dashboardsInput(): DashboardsInput
    {
        return new DashboardsInput(new Selector('shop', ['worker', 'web']));
    }

    /**
     * @param positive-int|null $noEventsAlertHours
     */
    public static function input(?int $noEventsAlertHours = null, bool $rules = true, bool $alerts = true): Input
    {
        return new Input(new Selector('shop', ['worker', 'web']), $rules, $alerts, $noEventsAlertHours);
    }
}
