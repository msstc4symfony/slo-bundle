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
        $prometheus = new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus/b.yml', 'b'), new GeneratedFile('prometheus/a.yml', 'a')]);
        $grafana = new GeneratedFileSet(['grafana'], [new GeneratedFile('grafana/dashboards/x.json', '{}')]);

        $merged = $prometheus->merge($grafana);

        self::assertSame(['prometheus/a.yml', 'prometheus/b.yml'], array_map(static fn (GeneratedFile $f): string => $f->relativePath, $prometheus->files));
        self::assertSame(['grafana', 'prometheus'], $merged->managedDirectories);
        self::assertSame(['grafana/dashboards/x.json', 'prometheus/a.yml', 'prometheus/b.yml'], array_map(static fn (GeneratedFile $f): string => $f->relativePath, $merged->files));
    }

    public function testSetRejectsAFileOutsideItsManagedDirectories(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"sloth/a.yml" is outside the managed directories prometheus');

        new GeneratedFileSet(['prometheus'], [new GeneratedFile('sloth/a.yml', 'a')]);
    }

    public function testSetRejectsAFileDirectlyInTheRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus', 'a')]);
    }

    public function testSetRejectsDuplicatePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"prometheus/a.yml" is generated twice');

        (new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus/a.yml', 'a')]))
            ->merge(new GeneratedFileSet(['prometheus'], [new GeneratedFile('prometheus/a.yml', 'b')]))
        ;
    }

    public function testSetRejectsAnInvalidManagedDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"../x"');

        new GeneratedFileSet(['../x'], []);
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
