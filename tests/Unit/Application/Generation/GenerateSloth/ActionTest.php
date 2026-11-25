<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerateSloth;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Action;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Input;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\SlothSpecBuilderInterface;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ActionTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../../../Fixtures/Generated/';

    public function testFixtureCatalogMatchesTheGoldenFiles(): void
    {
        $output = GenerationFixture::slothAction(GenerationFixture::catalog())(new Input(new Selector('shop', ['worker', 'web'])));

        self::assertSame(['sloth'], $output->files->managedDirectories);
        self::assertSame(
            ['sloth/slo-checkout.pay.yml', 'sloth/slo-cleanup.run.yml', 'sloth/slo-partners.sync.yml', 'sloth/slo-payment.process.yml', 'sloth/slo-report.export.yml'],
            array_map(static fn (GeneratedFile $file): string => $file->relativePath, $output->files->files),
        );
        foreach ($output->files->files as $file) {
            self::assertStringEqualsFile(self::GOLDEN . $file->relativePath, $file->contents, $file->relativePath);
        }
        self::assertCount(1, $output->warnings);
        self::assertStringContainsString('3d, 30d', $output->warnings[0]);
        self::assertStringContainsString('one --default-slo-period cannot match several windows', $output->warnings[0]);
        self::assertStringContainsString('SLOs whose window differs from the chosen period get wrong burn-rate thresholds', $output->warnings[0]);
    }

    public function testSpecsFollowTheSlothSchema(): void
    {
        $output = GenerationFixture::slothAction(GenerationFixture::catalog())(new Input(new Selector('shop', [])));
        $yaml = $output->files->files[0]->contents;
        $spec = Yaml::parse($yaml);

        self::assertIsArray($spec);
        self::assertSame('prometheus/v1', $spec['version']);
        self::assertSame('shop', $spec['service']);
        self::assertStringContainsString("    name: checkout.pay-availability\n    objective: 99.9\n", $yaml);
        self::assertStringContainsString("      name: SloCheckoutPayAvailability\n", $yaml);
        self::assertStringContainsString("      application: shop\n", $yaml);
        self::assertSame(2, substr_count($yaml, '    sli:'));
        self::assertSame(4, substr_count($yaml, '[{{.window}}]'));
        self::assertStringNotContainsString('[5m]', $yaml);
    }

    public function testTaskSpecsCountUnfinishedRunsInIncreaseForm(): void
    {
        $output = GenerationFixture::slothAction(GenerationFixture::catalog())(new Input(new Selector('shop', [])));
        $files = [];
        foreach ($output->files->files as $file) {
            $files[$file->relativePath] = $file->contents;
        }
        $spec = Yaml::parse($files['sloth/slo-partners.sync.yml']);
        self::assertIsArray($spec);
        self::assertIsArray($spec['slos']);
        self::assertCount(1, $spec['slos']);
        self::assertIsArray($spec['slos'][0]);
        self::assertIsArray($spec['slos'][0]['sli']);
        self::assertIsArray($spec['slos'][0]['sli']['events']);
        $events = $spec['slos'][0]['sli']['events'];

        foreach (['error_query', 'total_query'] as $query) {
            self::assertIsString($events[$query]);
            self::assertStringContainsString('last_over_time((symfony_slo_scheduled_task_started_total{application="shop",task="command:app:sync-partners"} offset 10m)[1m:1m] offset {{.window}})', $events[$query]);
            self::assertStringContainsString('resets(symfony_slo_scheduled_task_finished_total{application="shop",task="command:app:sync-partners"}[11m] offset {{.window}}) > 0', $events[$query]);
            self::assertStringNotContainsString('slo:unfinished_runs:step', $events[$query]);
            self::assertStringNotContainsString('rate(', $events[$query]);
        }
        self::assertStringContainsString('result="bad"}[{{.window}}]', $events['error_query']);
        self::assertStringNotContainsString('result="bad"', $events['total_query']);
        self::assertStringNotContainsString('scheduled_task', $files['sloth/slo-report.export.yml']);
    }

    public function testDisabledWritesNothingButStillManagesTheDirectory(): void
    {
        $output = GenerationFixture::slothAction(GenerationFixture::catalog())(new Input(new Selector('shop', []), false));

        self::assertSame(['sloth'], $output->files->managedDirectories);
        self::assertSame([], $output->files->files);
        self::assertSame([], $output->warnings);
    }

    public function testObjectivesTheBuilderCannotExpressAreSkippedWithAWarning(): void
    {
        $builder = new class implements SlothSpecBuilderInterface {
            #[Override]
            public function supports(BoundObjective $objective): bool
            {
                return $objective->definition->name()->value !== 'checkout.pay';
            }

            #[Override]
            public function spec(Selector $selector, array $objectives): string
            {
                return $objectives[0]->definition->name()->value . "\n";
            }
        };
        $catalog = GenerationFixture::catalog();
        $action = new Action(GenerationFixture::collector($catalog), $builder);
        $output = $action(new Input(new Selector('shop', [])));

        self::assertCount(4, $output->files->files);
        self::assertCount(3, $output->warnings);
        self::assertSame('SLO checkout.pay availability is not expressible as a Sloth ratio SLI and is skipped', $output->warnings[0]);
        self::assertSame('SLO checkout.pay latency is not expressible as a Sloth ratio SLI and is skipped', $output->warnings[1]);
        self::assertStringContainsString('one --default-slo-period cannot match several windows', $output->warnings[2]);
    }
}
