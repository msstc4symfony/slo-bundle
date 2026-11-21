<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\DependencyInjection;

use Closure;
use Msstc4Symfony\SloBundle\Application\Discovery\PointTag;
use Msstc4Symfony\SloBundle\Application\Discovery\TaggedPoint;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Test\Fixtures\Action\CleanupAction;
use Msstc4Symfony\SloBundle\Test\Fixtures\Action\RefundAction;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\AdminController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\CatalogController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\ConflictingSloController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid\InvalidValuesController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid\InvokableSloAndIgnoreController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid\SloAndIgnoreController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid\WrongOptionsController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\InvokableController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\PaymentController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\RepeatedSloController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\ReportController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\SharedSloController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\AuditPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\Invalid\IncludeErrorsReceiptHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\Invalid\LegacyPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\Invalid\SecondSloPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\ProcessPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\ReceiptHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\RefundPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\ProcessPayment;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\SendReceipt;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @phpstan-import-type TestConfig from TestContainer
 */
final class CollectSloDefinitionsPassTest extends TestCase
{
    public function testControllerMethodSloUsesDefaultWindow(): void
    {
        $catalog = $this->catalog([PaymentController::class]);

        $slo = $this->definition($catalog, 'payment.create');
        self::assertSame([$this->http(PaymentController::class, 'create')], $this->points($slo));
        self::assertCount(2, $slo->objectives());
        self::assertSame('30d', $slo->window()->toString());
        self::assertSame(StrategyPreference::Auto, $slo->strategy());
        self::assertSame(99.95, $slo->objectives()[0]->target->percent);
        self::assertSame(200, $slo->objectives()[1]->threshold?->milliseconds);

        $combined = $this->definition($catalog, 'payment.combined');
        self::assertSame('7d', $combined->window()->toString());
        self::assertSame(StrategyPreference::Dedicated, $combined->strategy());

        self::assertSame([$this->http(PaymentController::class, 'health') => 'health probe'], $catalog->ignored());
        self::assertSame([PaymentController::class => ['create', 'health']], $catalog->extras()->controllers);
        self::assertSame(['payment.combined', 'payment.create'], array_map(static fn (SloDefinition $d): string => $d->name()->value, $catalog->all()));
        self::assertCount(2, $catalog->forPoint(PointId::of(PointType::HttpRoute, PaymentController::class . '::create')));
    }

    public function testIdenticalDeclarationsOnTwoMethodsMergeIntoOneSharedSlo(): void
    {
        $catalog = $this->catalog([SharedSloController::class]);

        $slo = $this->definition($catalog, 'payment.shared');
        $points = [$this->http(SharedSloController::class, 'create'), $this->http(SharedSloController::class, 'createV2')];
        self::assertSame($points, $this->points($slo));
        self::assertSame(['payment.shared' => $points], array_map(
            static fn (array $list): array => array_map(static fn (PointId $p): string => $p->toString(), $list),
            $catalog->shared(),
        ));
        self::assertSame([], $catalog->conflicts());
    }

    public function testDifferingDeclarationsAreKeptAsConflicts(): void
    {
        $catalog = $this->catalog([ConflictingSloController::class]);

        self::assertNull($catalog->get(SloName::fromString('payment.create')));
        self::assertSame([], $catalog->all());
        $declarations = $catalog->conflicts()['payment.create'] ?? [];
        self::assertCount(2, $declarations);
        self::assertSame($this->http(ConflictingSloController::class, 'create'), $declarations[0]->point->toString());
        self::assertSame(99.9, $declarations[0]->definition->objectives()[0]->target->percent);
        self::assertSame(99.5, $declarations[1]->definition->objectives()[0]->target->percent);

        $point = PointId::of(PointType::HttpRoute, ConflictingSloController::class . '::createV2');
        self::assertSame([], $catalog->forPoint($point));
        self::assertSame(['payment.create'], $catalog->conflictedNamesFor($point));
        self::assertSame([], $catalog->conflictedNamesFor(PointId::of(PointType::HttpRoute, 'Other::method')));
    }

    public function testSameNameTwiceOnOnePointIsAConflict(): void
    {
        $catalog = $this->catalog([RepeatedSloController::class]);

        self::assertSame([], $catalog->all());
        self::assertCount(2, $catalog->conflicts()['payment.create'] ?? []);
    }

    public function testClassLevelSloOnInvokableControllerTargetsInvoke(): void
    {
        $catalog = $this->catalog([InvokableController::class]);

        self::assertSame([$this->http(InvokableController::class, '__invoke')], $this->points($this->definition($catalog, 'refund.create')));
        self::assertSame([InvokableController::class => ['__invoke']], $catalog->extras()->controllers);
    }

    public function testClassLevelSloOnNonInvokableControllerCoversEveryPublicActionMethod(): void
    {
        $catalog = $this->catalog([CatalogController::class]);

        self::assertSame(
            [$this->http(CatalogController::class, 'list'), $this->http(CatalogController::class, 'show')],
            $this->points($this->definition($catalog, 'catalog.read')),
        );
        self::assertSame([CatalogController::class => ['list', 'show']], $catalog->extras()->controllers);
    }

    public function testMethodIgnoreOverridesClassSloOnNonInvokableController(): void
    {
        $catalog = $this->catalog([ReportController::class]);

        self::assertSame([$this->http(ReportController::class, 'list')], $this->points($this->definition($catalog, 'report.read')));
        self::assertSame([$this->http(ReportController::class, 'debug') => 'debug only'], $catalog->ignored());
    }

    public function testMethodSloOverridesClassIgnoreOnNonInvokableController(): void
    {
        $catalog = $this->catalog([AdminController::class]);

        self::assertSame([$this->http(AdminController::class, 'status')], $this->points($this->definition($catalog, 'admin.status')));
        self::assertSame([$this->http(AdminController::class, 'settings') => 'admin area'], $catalog->ignored());
    }

    public function testControllerClassResolvedFromParameterAndUnloadableClassesSkipped(): void
    {
        $catalog = $this->catalog([], static function (ContainerBuilder $container): void {
            $container->setParameter('app.controller_class', InvokableController::class);
            $container->setDefinition('app.controller', new Definition('%app.controller_class%'))->addTag('controller.service_arguments');
            $container->setDefinition('app.missing', new Definition('App\\Missing\\Controller'))->addTag('controller.service_arguments');
        });

        self::assertNotNull($catalog->get(SloName::fromString('refund.create')));
        self::assertSame([InvokableController::class => ['__invoke']], $catalog->extras()->controllers);
    }

    public function testMessengerHandlerSloTargetsTheMessage(): void
    {
        $catalog = $this->catalog([], static function (ContainerBuilder $container): void {
            $container->register(ProcessPaymentHandler::class, ProcessPaymentHandler::class)->addTag('messenger.message_handler', ['from_transport' => 'async']);
            $container->register(ReceiptHandler::class, ReceiptHandler::class)->addTag('messenger.message_handler', ['method' => 'onReceipt']);
        });

        $slo = $this->definition($catalog, 'payment.process');
        self::assertSame(['messenger_message:' . ProcessPayment::class], $this->points($slo));
        self::assertTrue($slo->classification()->countAttempts);
        self::assertSame(['messenger_message:' . SendReceipt::class => 'best effort'], $catalog->ignored());

        $handlers = $catalog->extras()->handlers;
        self::assertCount(2, $handlers);
        self::assertSame(ProcessPaymentHandler::class, $handlers[0]->handlerClass);
        self::assertSame(ProcessPayment::class, $handlers[0]->messageClass);
        self::assertSame('async', $handlers[0]->fromTransport);
        self::assertFalse($handlers[0]->async);
        self::assertNull($handlers[1]->fromTransport);
        self::assertSame([], $catalog->extras()->warnings);
        self::assertSame([], $catalog->extras()->failureTransports);
    }

    public function testHandlesTagAttributeWinsOverSignature(): void
    {
        $catalog = $this->catalog([], static function (ContainerBuilder $container): void {
            $container->register(ProcessPaymentHandler::class, ProcessPaymentHandler::class)
                ->addTag('messenger.message_handler', ['handles' => SendReceipt::class])
            ;
        });

        self::assertSame(['messenger_message:' . SendReceipt::class], $this->points($this->definition($catalog, 'payment.process')));
    }

    public function testMessageWithSeveralHandlersIsAWarning(): void
    {
        $catalog = $this->catalog([], static function (ContainerBuilder $container): void {
            $container->register(ProcessPaymentHandler::class, ProcessPaymentHandler::class)->addTag('messenger.message_handler');
            $container->register(AuditPaymentHandler::class, AuditPaymentHandler::class)->addTag('messenger.message_handler');
        });

        self::assertCount(1, $catalog->extras()->warnings);
        self::assertStringContainsString('messenger_message:' . ProcessPayment::class . ' has 2 handlers', $catalog->extras()->warnings[0]);
    }

    public function testRoutingAndFailureTransportsAreReadFromMessengerDefinitions(): void
    {
        $catalog = $this->catalog([], static function (ContainerBuilder $container): void {
            $container->register(LegacyPaymentHandler::class, LegacyPaymentHandler::class)->addTag('messenger.message_handler');
            $container->register(ReceiptHandler::class, ReceiptHandler::class)->addTag('messenger.message_handler', ['method' => 'onReceipt']);
            $container->register('messenger.senders_locator', 'stdClass')
                ->setArguments([['Msstc4Symfony\\SloBundle\\Test\\Fixtures\\Message\\Legacy\\*' => ['async'], '*' => [], SendReceipt::class => []], null])
            ;
            $container->register('messenger.failure.send_failed_message_to_failure_transport_listener', 'stdClass')
                ->setArguments([null, null, ['async' => 'failed', 'failed' => 'messenger.transport.failed', 'other' => 'dead']])
            ;
        });

        self::assertTrue($catalog->extras()->handlers[0]->async);
        self::assertFalse($catalog->extras()->handlers[1]->async);
        self::assertSame(['dead', 'failed'], $catalog->extras()->failureTransports);
    }

    public function testAsMessageTransportCountsAsRoutingOnlyWithMessenger(): void
    {
        $handler = static function (ContainerBuilder $container): void {
            $container->register(RefundPaymentHandler::class, RefundPaymentHandler::class)->addTag('messenger.message_handler');
        };

        self::assertFalse($this->catalog([], $handler)->extras()->handlers[0]->async);
        self::assertTrue($this->catalog([], static function (ContainerBuilder $container) use ($handler): void {
            $handler($container);
            $container->register('messenger.senders_locator', 'stdClass')->setArguments([[SendReceipt::class => ['sync']], null]);
        })->extras()->handlers[0]->async);
    }

    public function testConfiguredFailureTransportsWin(): void
    {
        $catalog = $this->catalog([], static function (ContainerBuilder $container): void {
            $container->register('messenger.failure.send_failed_message_to_failure_transport_listener', 'stdClass')
                ->setArguments([null, null, ['async' => 'failed']])
            ;
        }, ['defaults' => ['messenger' => ['failure_transports' => ['manual']]]]);

        self::assertSame(['manual'], $catalog->extras()->failureTransports);
    }

    public function testTaggedPointsAreDiscoveredWithMetricLabels(): void
    {
        $catalog = $this->catalog([], static function (ContainerBuilder $container): void {
            $container->register(RefundAction::class, RefundAction::class)
                ->addTag(PointTag::TAG, [PointTag::TYPE => 'logic_action', PointTag::ID => 'refund', PointTag::METRIC_LABEL => 'RefundAction'])
            ;
            $container->register(CleanupAction::class, CleanupAction::class)
                ->addTag(PointTag::TAG, [PointTag::TYPE => 'logic_action', PointTag::ID => 'cleanup'])
            ;
        });

        $slo = $this->definition($catalog, 'refund.action');
        self::assertSame(['logic_action:refund'], $this->points($slo));
        self::assertFalse($slo->classification()->expectedFailureIsGood);
        self::assertSame(['logic_action:cleanup' => 'cron only'], $catalog->ignored());

        $tagged = $catalog->extras()->taggedPoints;
        self::assertSame(['logic_action:cleanup', 'logic_action:refund'], array_map(static fn (TaggedPoint $t): string => $t->point->toString(), $tagged));
        self::assertNull($tagged[0]->metricLabel);
        self::assertSame('RefundAction', $tagged[1]->metricLabel);
    }

    public function testConfiguredOperationIsADedicatedSlo(): void
    {
        $catalog = $this->catalog([], null, ['operations' => ['payment.validation' => [
            'objectives' => [['type' => 'availability', 'target' => 99.9], ['type' => 'latency', 'threshold' => '50ms', 'target' => 99]],
            'window' => '7d',
        ]]]);

        $slo = $this->definition($catalog, 'payment.validation');
        self::assertSame(['operation:payment.validation'], $this->points($slo));
        self::assertSame(StrategyPreference::Dedicated, $slo->strategy());
        self::assertSame('7d', $slo->window()->toString());
        self::assertTrue($slo->classification()->expectedFailureIsGood);
        self::assertSame(['payment.validation'], $catalog->extras()->operations);
    }

    public function testMetricBucketsAndIgnoredRoutesComeFromMetricsBundleParameters(): void
    {
        $catalog = $this->catalog([], static function (ContainerBuilder $container): void {
            $container->setParameter('msstc4symfony_metrics.metric_enums', []);
            $container->setParameter('msstc4symfony_metrics.http.ignored_routes', ['health']);
        });

        $buckets = $catalog->extras()->metricBuckets;
        self::assertSame(['messenger_message_handling_duration_histogram_seconds', 'request_duration_histogram_seconds'], array_keys($buckets));
        self::assertSame([0.1, 0.5, 1.0, 3.0, 5.0, 10.0, 15.0, 30.0, 45.0, 60.0], $buckets['request_duration_histogram_seconds']);
        self::assertSame(['health'], $catalog->extras()->httpIgnoredRoutes);
    }

    public function testMissingMetricsParametersLeaveExtrasEmpty(): void
    {
        $catalog = $this->catalog([]);

        self::assertSame([], $catalog->extras()->metricBuckets);
        self::assertSame([], $catalog->extras()->httpIgnoredRoutes);
    }

    public function testCatalogIsAvailableThroughTheInterface(): void
    {
        $container = $this->container([PaymentController::class], static function (ContainerBuilder $container): void {
            $container->setAlias('test.catalog', SloCatalogInterface::class)->setPublic(true);
        });

        self::assertInstanceOf(CompiledSloCatalog::class, $container->get('test.catalog'));
    }

    /**
     * @return iterable<string, array{list<class-string>, (Closure(ContainerBuilder): void)|null, TestConfig, non-empty-string}>
     */
    public static function compileErrors(): iterable
    {
        $http = static fn (string $class, string $method): string => 'http_route:' . $class . '::' . $method;

        yield 'slo and ignore on one point' => [[SloAndIgnoreController::class], null, [], $http(SloAndIgnoreController::class, 'status') . ': #[Slo] and #[IgnoreMonitoring]'];
        yield 'slo on invoke and ignore on invokable class' => [[InvokableSloAndIgnoreController::class], null, [], $http(InvokableSloAndIgnoreController::class, '__invoke') . ': #[Slo] and #[IgnoreMonitoring]'];
        yield 'invalid window' => [[InvalidValuesController::class], null, [], $http(InvalidValuesController::class, 'window') . ': Invalid SLO window "5m"'];
        yield 'invalid target' => [[InvalidValuesController::class], null, [], $http(InvalidValuesController::class, 'target') . ': Invalid SLO target "120.0"'];
        yield 'invalid threshold' => [[InvalidValuesController::class], null, [], $http(InvalidValuesController::class, 'threshold') . ': Invalid latency threshold "fast"'];
        yield 'invalid name' => [[InvalidValuesController::class], null, [], $http(InvalidValuesController::class, 'name') . ': Invalid SLO name "Payment Name"'];
        yield 'overlapping statuses' => [[InvalidValuesController::class], null, [], $http(InvalidValuesController::class, 'statuses') . ': Bad statuses "5.." overlap ineligible statuses "503"'];
        yield 'invalid strategy' => [[InvalidValuesController::class], null, [], $http(InvalidValuesController::class, 'strategy') . ': SLO "payment.strategy": invalid strategy "fast", expected auto|dedicated'];
        yield 'count attempts on http' => [[WrongOptionsController::class], null, [], $http(WrongOptionsController::class, 'attempts') . ': SLO "payment.attempts": countAttempts applies to messenger_message points only'];
        yield 'expected failure on http' => [[WrongOptionsController::class], null, [], $http(WrongOptionsController::class, 'expected') . ': SLO "payment.expected": expectedFailureIsGood applies to logic_action and operation points only'];
        yield 'include errors on messenger' => [[], static function (ContainerBuilder $container): void {
            $container->register(IncludeErrorsReceiptHandler::class, IncludeErrorsReceiptHandler::class)->addTag('messenger.message_handler');
        }, [], 'messenger_message:' . SendReceipt::class . ': SLO "receipt.send": includeErrors applies to http_route points only'];
        yield 'include errors on operation' => [[], null, ['operations' => ['payment.validation' => [
            'objectives' => [['type' => 'latency', 'threshold' => '50ms', 'target' => 99, 'include_errors' => true]],
        ]]], 'operation:payment.validation: SLO "payment.validation": includeErrors applies to http_route points only'];
        yield 'slo on two handlers of one message' => [[], static function (ContainerBuilder $container): void {
            $container->register(ProcessPaymentHandler::class, ProcessPaymentHandler::class)->addTag('messenger.message_handler');
            $container->register(SecondSloPaymentHandler::class, SecondSloPaymentHandler::class)->addTag('messenger.message_handler');
        }, [], 'messenger_message:' . ProcessPayment::class . ': #[Slo] is declared on 2 handlers ('];
        yield 'equal short message names' => [[], static function (ContainerBuilder $container): void {
            $container->register(ProcessPaymentHandler::class, ProcessPaymentHandler::class)->addTag('messenger.message_handler');
            $container->register(LegacyPaymentHandler::class, LegacyPaymentHandler::class)->addTag('messenger.message_handler');
        }, [], 'messenger_message:' . ProcessPayment::class . ': short class name "ProcessPayment" is also used by SLO message'];
        yield 'invalid tagged point type' => [[], static function (ContainerBuilder $container): void {
            $container->register(RefundAction::class, RefundAction::class)->addTag(PointTag::TAG, [PointTag::TYPE => 'job', PointTag::ID => 'refund']);
        }, [], 'service "' . RefundAction::class . '": tag "msstc4symfony_slo.point" needs attribute "type"'];
    }

    /**
     * @param list<class-string> $controllers
     * @param (Closure(ContainerBuilder): void)|null $configure
     * @param TestConfig $config
     * @param non-empty-string $expected
     */
    #[DataProvider('compileErrors')]
    public function testCompileError(array $controllers, ?Closure $configure, array $config, string $expected): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expected);

        $this->container($controllers, $configure, $config);
    }

    public function testAllErrorsAreReportedTogetherAndSorted(): void
    {
        try {
            $this->container([WrongOptionsController::class, SloAndIgnoreController::class]);
            self::fail('Compilation must fail.');
        } catch (InvalidConfigurationException $e) {
            $message = $e->getMessage();
            $attempts = strpos($message, $this->http(WrongOptionsController::class, 'attempts'));
            $expected = strpos($message, $this->http(WrongOptionsController::class, 'expected'));
            $ignore = strpos($message, $this->http(SloAndIgnoreController::class, 'status'));
            self::assertIsInt($attempts);
            self::assertIsInt($expected);
            self::assertIsInt($ignore);
            self::assertTrue($ignore < $attempts && $attempts < $expected, $message);
            self::assertStringStartsWith('SLO discovery found 3 errors:', $message);
        }
    }

    /**
     * @param list<class-string> $controllers
     * @param (Closure(ContainerBuilder): void)|null $configure
     * @param TestConfig $config
     */
    private function catalog(array $controllers, ?Closure $configure = null, array $config = []): CompiledSloCatalog
    {
        $catalog = $this->container($controllers, $configure, $config)->get(CompiledSloCatalog::class);
        self::assertInstanceOf(CompiledSloCatalog::class, $catalog);

        return $catalog;
    }

    /**
     * @param list<class-string> $controllers
     * @param (Closure(ContainerBuilder): void)|null $configure
     * @param TestConfig $config
     */
    private function container(array $controllers, ?Closure $configure = null, array $config = []): ContainerBuilder
    {
        return TestContainer::compile($config, static function (ContainerBuilder $container) use ($controllers, $configure): void {
            foreach ($controllers as $controller) {
                $container->register($controller, $controller)->addTag('controller.service_arguments');
            }
            if ($configure instanceof Closure) {
                $configure($container);
            }
        });
    }

    private function definition(CompiledSloCatalog $catalog, string $name): SloDefinition
    {
        $definition = $catalog->get(SloName::fromString($name));
        self::assertInstanceOf(SloDefinition::class, $definition, $name);

        return $definition;
    }

    /**
     * @return list<string>
     */
    private function points(SloDefinition $definition): array
    {
        return array_map(static fn (PointId $p): string => $p->toString(), $definition->points());
    }

    private function http(string $class, string $method): string
    {
        return 'http_route:' . $class . '::' . $method;
    }
}
