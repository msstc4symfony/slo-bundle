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
            ['sloth/slo-checkout.pay.yml', 'sloth/slo-payment.process.yml', 'sloth/slo-report.export.yml'],
            array_map(static fn (GeneratedFile $file): string => $file->relativePath, $output->files->files),
        );
        foreach ($output->files->files as $file) {
            self::assertStringEqualsFile(self::GOLDEN . $file->relativePath, $file->contents, $file->relativePath);
        }
        self::assertCount(1, $output->warnings);
        self::assertStringContainsString('3d, 30d', $output->warnings[0]);
        self::assertStringContainsString('--default-slo-period', $output->warnings[0]);
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

        self::assertCount(2, $output->files->files);
        self::assertSame(
            ['SLO checkout.pay availability is not expressible as a Sloth ratio SLI and is skipped', 'SLO checkout.pay latency is not expressible as a Sloth ratio SLI and is skipped'],
            \array_slice($output->warnings, 0, 2),
        );
    }
}
