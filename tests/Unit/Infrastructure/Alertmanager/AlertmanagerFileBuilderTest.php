<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Alertmanager;

use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Infrastructure\Alertmanager\AlertmanagerFileBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AlertmanagerFileBuilderTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../../Fixtures/Generated/alertmanager/';

    public function testDefaultReceiversMatchTheGoldenFiles(): void
    {
        $builder = $this->builder();
        $selector = new Selector('showcase', []);
        $receivers = new AlertmanagerReceivers('slo-page', 'slo-ticket');

        self::assertStringEqualsFile(self::GOLDEN . 'slo-alertmanager.yml', $builder->full($selector, $receivers));
        self::assertStringEqualsFile(self::GOLDEN . 'slo-alertmanager-routes.yml', $builder->routes($selector, $receivers));
    }

    public function testComponentsDoNotChangeTheMatchers(): void
    {
        $builder = $this->builder();
        $receivers = new AlertmanagerReceivers('slo-page', 'slo-ticket');

        self::assertStringEqualsFile(self::GOLDEN . 'slo-alertmanager.yml', $builder->full(new Selector('showcase', ['web', 'worker']), $receivers));
        self::assertStringEqualsFile(self::GOLDEN . 'slo-alertmanager-routes.yml', $builder->routes(new Selector('showcase', ['web']), $receivers));
    }

    public function testOneReceiverForBothSeveritiesIsListedOnce(): void
    {
        $builder = $this->builder();
        $selector = new Selector('shop', []);
        $receivers = new AlertmanagerReceivers('ops', 'ops');
        $route = [
            'group_by' => ['application', 'slo', 'objective'],
            'matchers' => ['alertname=~"SloErrorBudgetBurn|SloNoEvents"', 'application="shop"'],
            'routes' => [
                ['matchers' => ['severity="page"'], 'receiver' => 'ops'],
                ['matchers' => ['severity="ticket"'], 'receiver' => 'ops'],
            ],
        ];
        $inhibit = [
            'equal' => ['application', 'slo', 'objective'],
            'source_matchers' => ['alertname="SloErrorBudgetBurn"', 'severity="page"'],
            'target_matchers' => ['alertname="SloErrorBudgetBurn"', 'severity="ticket"'],
        ];

        self::assertSame(
            [
                'inhibit_rules' => [$inhibit],
                'receivers' => [['name' => 'slo-unrouted'], ['name' => 'ops']],
                'route' => ['receiver' => 'slo-unrouted', 'routes' => [$route]],
            ],
            Yaml::parse($builder->full($selector, $receivers)),
        );
        self::assertSame(['inhibit_rules' => [$inhibit], 'routes' => [$route]], Yaml::parse($builder->routes($selector, $receivers)));
    }

    public function testReceiverNamedLikeTheRootReceiverIsListedOnce(): void
    {
        self::assertStringContainsString(
            "receivers:\n  - name: slo-unrouted\n  - name: tickets\nroute:\n",
            $this->builder()->full(new Selector('shop', []), new AlertmanagerReceivers('slo-unrouted', 'tickets')),
        );
    }

    public function testDistinctReceiversFollowTheRootReceiverInPageTicketOrder(): void
    {
        self::assertStringContainsString(
            "receivers:\n  - name: slo-unrouted\n  - name: zz-page\n  - name: aa-ticket\nroute:\n",
            $this->builder()->full(new Selector('shop', []), new AlertmanagerReceivers('zz-page', 'aa-ticket')),
        );
    }

    private function builder(): AlertmanagerFileBuilder
    {
        return new AlertmanagerFileBuilder(new DeterministicYaml());
    }
}
