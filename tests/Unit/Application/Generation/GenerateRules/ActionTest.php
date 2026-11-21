<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerateRules;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Output;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ActionTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../../../Fixtures/Generated/';

    public function testFixtureCatalogMatchesTheGoldenFiles(): void
    {
        $output = GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input());

        self::assertSame(['prometheus'], $output->files->managedDirectories);
        self::assertSame(['prometheus/slo-alerts.rules.yml', 'prometheus/slo-recording.rules.yml'], $this->paths($output));
        foreach ($output->files->files as $file) {
            self::assertStringEqualsFile(self::GOLDEN . $file->relativePath, $file->contents, $file->relativePath);
        }
        self::assertSame([], $output->warnings);
    }

    public function testGeneratingTwiceIsByteIdentical(): void
    {
        $first = GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input(6));
        $second = GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input(6));

        self::assertEquals($first->files, $second->files);
    }

    public function testChangingOnlyTheTargetKeepsTheRevisionFilterAndChangesTheTargetConstant(): void
    {
        $before = $this->recording(GenerationFixture::action(GenerationFixture::catalog(99.0))(GenerationFixture::input()));
        $after = $this->recording(GenerationFixture::action(GenerationFixture::catalog(98.5))(GenerationFixture::input()));
        $definition = GenerationFixture::catalog()->get(SloName::fromString('checkout.pay'));
        self::assertNotNull($definition);
        $revision = (new RevisionCalculator())->revision($definition, ObjectiveKind::Latency)->value;

        self::assertStringContainsString('revision="' . $revision . '"', $before);
        self::assertStringContainsString('revision="' . $revision . '"', $after);
        self::assertStringContainsString("expr: vector(0.99)\n", $before);
        self::assertStringContainsString("expr: vector(0.985)\n", $after);
        self::assertStringNotContainsString('vector(0.985)', $before);
        self::assertSame(substr_count($before, 'revision="'), substr_count($after, 'revision="'));
    }

    public function testEveryObjectiveGetsOneGroupPerSloAndTheDocumentedRecords(): void
    {
        $recording = Yaml::parse($this->recording(GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input())));
        self::assertIsArray($recording);
        self::assertIsArray($recording['groups']);

        $groups = [];
        foreach ($recording['groups'] as $group) {
            self::assertIsArray($group);
            self::assertSame('1m', $group['interval']);
            self::assertIsString($group['name']);
            self::assertIsArray($group['rules']);
            $groups[$group['name']] = array_map(static function (mixed $rule): string {
                self::assertIsArray($rule);
                self::assertIsArray($rule['labels']);
                self::assertIsString($rule['record']);
                self::assertIsString($rule['labels']['objective']);

                return $rule['record'] . ' ' . $rule['labels']['objective'] . (isset($rule['labels']['revision']) ? ' B' : ' A');
            }, $group['rules']);
        }

        self::assertSame(['slo-checkout.pay', 'slo-payment.process', 'slo-report.export'], array_keys($groups));
        $records = static fn (string $objective, string $strategy): array => [
            'slo:events:rate5m ' . $objective . ' ' . $strategy,
            'slo:error_ratio:rate5m ' . $objective . ' ' . $strategy,
            'slo:events:rate30m ' . $objective . ' ' . $strategy,
            'slo:error_ratio:rate30m ' . $objective . ' ' . $strategy,
            'slo:events:rate1h ' . $objective . ' ' . $strategy,
            'slo:error_ratio:rate1h ' . $objective . ' ' . $strategy,
            'slo:events:rate6h ' . $objective . ' ' . $strategy,
            'slo:error_ratio:rate6h ' . $objective . ' ' . $strategy,
            'slo:events:rate3d ' . $objective . ' ' . $strategy,
            'slo:error_ratio:rate3d ' . $objective . ' ' . $strategy,
            'slo:sli:ratio ' . $objective . ' ' . $strategy,
            'slo:objective:target ' . $objective . ' ' . $strategy,
            'slo:error_budget:remaining ' . $objective . ' ' . $strategy,
            'slo:window:coverage_ratio ' . $objective . ' ' . $strategy,
        ];
        self::assertSame([...$records('availability', 'A'), ...$records('latency', 'B')], $groups['slo-checkout.pay']);
        self::assertSame([...$records('availability', 'A'), ...$records('latency', 'A')], $groups['slo-payment.process']);
        self::assertSame($records('availability', 'B'), $groups['slo-report.export']);
    }

    public function testNoEventsAlertIsOptIn(): void
    {
        $without = $this->alerts(GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input()));
        $with = $this->alerts(GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input(6)));

        self::assertStringNotContainsString('SloNoEvents', $without);
        self::assertSame(5, substr_count($with, 'alert: SloNoEvents'));
        self::assertStringContainsString('[6h]', $with);
        self::assertStringContainsString('recorded no events in 6h', $with);
    }

    public function testDisabledFilesAreNotGeneratedButStayManaged(): void
    {
        $action = GenerationFixture::action(GenerationFixture::catalog());

        self::assertSame(['prometheus/slo-recording.rules.yml'], $this->paths($action(GenerationFixture::input(alerts: false))));
        self::assertSame(['prometheus/slo-alerts.rules.yml'], $this->paths($action(GenerationFixture::input(rules: false))));
        $none = $action(GenerationFixture::input(rules: false, alerts: false));
        self::assertSame([], $this->paths($none));
        self::assertSame(['prometheus'], $none->files->managedDirectories);
    }

    public function testOmittedBurnRatePairsAreWarnedAndLeftOut(): void
    {
        $output = GenerationFixture::action(GenerationFixture::catalog(checkoutAvailabilityTarget: 90.0))(GenerationFixture::input());

        self::assertSame(['SLO checkout.pay availability: page burn-rate alert 1h/5m omitted, factor 14.4 x error budget 0.1 >= 1'], $output->warnings);
        self::assertStringNotContainsString('> 1.44', $this->alerts($output));
        self::assertStringContainsString('> 0.6', $this->alerts($output));
    }

    public function testConflictingDeclarationsGetNoRulesAndAWarning(): void
    {
        $catalog = CatalogFixture::build(static function ($assembler): void {
            $assembler->declare(PointId::of(PointType::Operation, 'a.b'), new Slo('a.b', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::Operation, 'a.b'), new Slo('a.b', [new Availability(99.5)]));
        });

        $output = GenerationFixture::action($catalog)(GenerationFixture::input());

        self::assertSame(['SLO a.b is declared differently on several points and gets no rules, see slo:validate'], $output->warnings);
        self::assertStringContainsString('groups: []', $this->recording($output));
        self::assertStringContainsString('groups: []', $this->alerts($output));
    }

    /**
     * @return list<string>
     */
    private function paths(Output $output): array
    {
        return array_map(static fn (GeneratedFile $file): string => $file->relativePath, $output->files->files);
    }

    private function recording(Output $output): string
    {
        return $this->contents($output, Action::RECORDING_FILE);
    }

    private function alerts(Output $output): string
    {
        return $this->contents($output, Action::ALERTS_FILE);
    }

    private function contents(Output $output, string $path): string
    {
        foreach ($output->files->files as $file) {
            if ($file->relativePath === $path) {
                return $file->contents;
            }
        }
        self::fail($path . ' was not generated');
    }
}
