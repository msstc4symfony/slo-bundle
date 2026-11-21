<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit;

use Msstc4Symfony\SloBundle\SloBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class SloBundleConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);

        $expected = [
            'defaults.window' => '30d',
            'defaults.http.bad_statuses' => ['5xx'],
            'defaults.http.ineligible_statuses' => [],
            'defaults.logic.expected_failure_is_good' => true,
            'defaults.messenger.count_attempts' => false,
            'defaults.messenger.failure_transports' => null,
            'coverage.http_routes.required_percent' => 95,
            'coverage.http_routes.ignored_routes' => [],
            'coverage.messenger_handlers.required_percent' => 95,
            'coverage.logic_actions.required_percent' => 0,
            'operations' => [],
            'generation.selector.application' => null,
            'generation.selector.components' => [],
            'generation.output_dir' => '%kernel.project_dir%/infrastructure/observability',
            'generation.grafana.datasource_uid' => 'prometheus',
            'generation.grafana.folder' => 'SLO',
            'generation.prometheus.rules' => true,
            'generation.prometheus.alerts' => true,
            'generation.prometheus.no_events_alert_hours' => null,
            'generation.sloth' => false,
        ];

        foreach ($expected as $path => $value) {
            self::assertTrue($container->hasParameter('msstc4symfony_slo.' . $path), $path);
            self::assertSame($value, $container->getParameter('msstc4symfony_slo.' . $path), $path);
        }
    }

    public function testOperationNormalises(): void
    {
        $container = $this->load([
            'operations' => [
                'payment.validation' => [
                    'objectives' => [['type' => 'latency', 'threshold' => '50ms', 'target' => 99]],
                ],
                'payment.capture' => [
                    'window' => '7d',
                    'expected_failure_is_good' => false,
                    'objectives' => [['type' => 'availability', 'target' => 99.9], ['type' => 'latency', 'threshold' => '1s', 'target' => 95, 'include_errors' => true]],
                ],
            ],
        ]);

        self::assertEquals([
            'payment.validation' => [
                'objectives' => [['type' => 'latency', 'target' => 99.0, 'threshold' => '50ms']],
            ],
            'payment.capture' => [
                'objectives' => [
                    ['type' => 'availability', 'target' => 99.9],
                    ['type' => 'latency', 'target' => 95.0, 'threshold' => '1s', 'include_errors' => true],
                ],
                'window' => '7d',
                'expected_failure_is_good' => false,
            ],
        ], $container->getParameter('msstc4symfony_slo.operations'));
    }

    public function testDashedOperationNameIsKeptUnchanged(): void
    {
        $container = $this->load([
            'operations' => [
                'payment-validation' => ['objectives' => [['type' => 'availability', 'target' => 99]]],
            ],
        ]);

        $operations = $container->getParameter('msstc4symfony_slo.operations');
        self::assertIsArray($operations);
        self::assertSame(['payment-validation'], array_keys($operations));
    }

    public function testNoEventsAlertHoursAndSelectorAreAccepted(): void
    {
        $container = $this->load([
            'generation' => [
                'selector' => ['application' => 'showcase', 'components' => ['gateway']],
                'prometheus' => ['no_events_alert_hours' => 6],
            ],
            'defaults' => ['messenger' => ['failure_transports' => ['failed']]],
        ]);

        self::assertSame('showcase', $container->getParameter('msstc4symfony_slo.generation.selector.application'));
        self::assertSame(['gateway'], $container->getParameter('msstc4symfony_slo.generation.selector.components'));
        self::assertSame(6, $container->getParameter('msstc4symfony_slo.generation.prometheus.no_events_alert_hours'));
        self::assertSame(['failed'], $container->getParameter('msstc4symfony_slo.defaults.messenger.failure_transports'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSelectorProvider(): iterable
    {
        yield 'quote in application' => [['application' => 'shop"} or vector(1) #']];
        yield 'space in application' => [['application' => 'my shop']];
        yield 'empty application' => [['application' => '']];
        yield 'too long application' => [['application' => str_repeat('a', 129)]];
        yield 'brace in component' => [['components' => ['web', 'w}']]];
        yield 'empty component' => [['components' => ['']]];
    }

    /**
     * @param array<string, mixed> $selector
     */
    #[DataProvider('invalidSelectorProvider')]
    public function testInvalidSelectorIsRejected(array $selector): void
    {
        $this->assertRejected(['generation' => ['selector' => $selector]]);
    }

    public function testSelectorAcceptsLabelValueCharacters(): void
    {
        $container = $this->load(['generation' => ['selector' => ['application' => 'Shop_1.eu-west:prod', 'components' => [str_repeat('c', 128)]]]]);

        self::assertSame('Shop_1.eu-west:prod', $container->getParameter('msstc4symfony_slo.generation.selector.application'));
    }

    public function testUnknownObjectiveTypeIsRejected(): void
    {
        $this->assertRejected(['operations' => ['a.b' => ['objectives' => [['type' => 'throughput', 'target' => 99]]]]]);
    }

    public function testOperationStrategyKeyIsRejected(): void
    {
        $this->assertRejected(['operations' => ['a.b' => ['strategy' => 'auto', 'objectives' => [['type' => 'availability', 'target' => 99]]]]]);
    }

    public function testOperationWithoutObjectivesIsRejected(): void
    {
        $this->assertRejected(['operations' => ['a.b' => ['window' => '7d']]]);
    }

    public function testObjectiveWithoutTargetIsRejected(): void
    {
        $this->assertRejected(['operations' => ['a.b' => ['objectives' => [['type' => 'availability']]]]]);
    }

    public function testEmptyWindowIsRejected(): void
    {
        $this->assertRejected(['defaults' => ['window' => '']]);
    }

    public function testNonPositiveNoEventsHoursIsRejected(): void
    {
        $this->assertRejected(['generation' => ['prometheus' => ['no_events_alert_hours' => 0]]]);
    }

    public function testUnknownKeyIsRejected(): void
    {
        $this->assertRejected(['defaults' => ['unknown' => 1]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertRejected(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $extension = (new SloBundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);
        $extension->load([$config], $container);

        return $container;
    }
}
