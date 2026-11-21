<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratedFilesTest extends TestCase
{
    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function invalidPathProvider(): iterable
    {
        yield 'absolute' => ['/etc/passwd'];
        yield 'parent segment' => ['prometheus/../x.yml'];
        yield 'dot segment' => ['prometheus/./x.yml'];
        yield 'empty segment' => ['prometheus//x.yml'];
        yield 'trailing slash' => ['prometheus/'];
        yield 'backslash' => ['prometheus\x.yml'];
    }

    /**
     * @param non-empty-string $path
     */
    #[DataProvider('invalidPathProvider')]
    public function testFileRejectsPathsLeavingTheOutputDirectory(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($path);

        new GeneratedFile($path, "x\n");
    }

    public function testSetSortsFilesAndMergesManagedDirectories(): void
    {
        $prometheus = new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus/slo-b.rules.yml', 'b'), new GeneratedFile('prometheus/slo-a.rules.yml', 'a')]);
        $grafana = new GeneratedFileSet(['grafana/dashboards'], [new GeneratedFile('grafana/dashboards/slo-x.json', '{}')]);

        $merged = $prometheus->merge($grafana);

        self::assertSame(['prometheus/slo-a.rules.yml', 'prometheus/slo-b.rules.yml'], array_map(static fn (GeneratedFile $f): string => $f->relativePath, $prometheus->files));
        self::assertSame(['grafana/dashboards', 'prometheus'], $merged->managedDirectories);
        self::assertSame(['grafana/dashboards/slo-x.json', 'prometheus/slo-a.rules.yml', 'prometheus/slo-b.rules.yml'], array_map(static fn (GeneratedFile $f): string => $f->relativePath, $merged->files));
    }

    public function testOnlyNamePatternedFilesDirectlyInAManagedDirectoryAreOwned(): void
    {
        $set = new GeneratedFileSet(['prometheus', 'grafana/provisioning/dashboards', 'sloth'], []);

        self::assertTrue($set->owns('prometheus/slo-old.rules.yml'));
        self::assertTrue($set->owns('grafana/provisioning/dashboards/slo.yaml'));
        self::assertTrue($set->owns('sloth/slo-checkout.pay.yml'));
        self::assertFalse($set->owns('prometheus/prometheus.yml'));
        self::assertFalse($set->owns('prometheus/rules.yml'));
        self::assertFalse($set->owns('prometheus/.gitkeep'));
        self::assertFalse($set->owns('prometheus/nested/slo-x.rules.yml'));
        self::assertFalse($set->owns('grafana/provisioning/dashboards/other.yaml'));
        self::assertFalse($set->owns('grafana/dashboards/slo-x.json'));
        self::assertFalse($set->owns('slo-x.rules.yml'));
    }

    public function testSetRejectsAFileOutsideItsManagedDirectories(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"sloth/slo-a.yml" is not an owned file of the managed directories prometheus');

        new GeneratedFileSet(['prometheus'], [new GeneratedFile('sloth/slo-a.yml', 'a')]);
    }

    public function testSetRejectsAFileNotMatchingTheOwnedPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"prometheus/prometheus.yml"');

        new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus/prometheus.yml', 'a')]);
    }

    public function testSetRejectsAFileDirectlyInTheRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus', 'a')]);
    }

    public function testSetRejectsDuplicatePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"prometheus/slo-a.rules.yml" is generated twice');

        (new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus/slo-a.rules.yml', 'a')]))
            ->merge(new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus/slo-a.rules.yml', 'b')]))
        ;
    }

    public function testSetRejectsAnInvalidManagedDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"grafana" is not a generated directory');

        new GeneratedFileSet(['grafana'], []);
    }

    public function testSelectorSortsAndDeduplicatesComponents(): void
    {
        $selector = new Selector('shop', ['worker', 'web', 'worker']);

        self::assertSame('shop', $selector->application);
        self::assertSame(['web', 'worker'], $selector->components);
    }

    public function testSelectorRejectsAnEmptyComponent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('generation.selector.components');

        new Selector('shop', ['web', '']);
    }
}
