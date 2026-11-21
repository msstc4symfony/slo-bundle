<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\SyncFiles;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Action;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Input;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testCheckReportsStaleMissingAndExtraFilesWithoutTouchingTheDisk(): void
    {
        $disk = [
            '/out/prometheus/current.yml' => "same\n",
            '/out/prometheus/stale.yml' => "old\n",
            '/out/prometheus/nested/extra.yml' => "x\n",
            '/out/unmanaged/keep.yml' => "x\n",
        ];
        $writer = new InMemoryFileWriter($disk);

        $output = (new Action($writer))(new Input($this->set(), '/out', true));

        self::assertFalse($output->isUpToDate());
        self::assertSame(['prometheus/stale.yml'], $output->stale);
        self::assertSame(['prometheus/missing.yml'], $output->missing);
        self::assertSame(['prometheus/nested/extra.yml'], $output->extra);
        self::assertSame($disk, $writer->files);
    }

    public function testWriteCreatesAndUpdatesAndRemovesExtraFilesOfManagedDirectoriesOnly(): void
    {
        $writer = new InMemoryFileWriter([
            '/out/prometheus/current.yml' => "same\n",
            '/out/prometheus/stale.yml' => "old\n",
            '/out/prometheus/nested/extra.yml' => "x\n",
            '/out/unmanaged/keep.yml' => "x\n",
        ]);

        $output = (new Action($writer))(new Input($this->set(), '/out', false));

        self::assertSame(['prometheus/stale.yml'], $output->stale);
        self::assertSame(
            [
                '/out/prometheus/current.yml' => "same\n",
                '/out/prometheus/missing.yml' => "new\n",
                '/out/prometheus/stale.yml' => "new\n",
                '/out/unmanaged/keep.yml' => "x\n",
            ],
            $writer->files,
        );
        self::assertTrue((new Action($writer))(new Input($this->set(), '/out', true))->isUpToDate());
    }

    public function testEmptyOutputDirectoryIsAllMissing(): void
    {
        $output = (new Action(new InMemoryFileWriter()))(new Input($this->set(), '/out', true));

        self::assertSame(['prometheus/current.yml', 'prometheus/missing.yml', 'prometheus/stale.yml'], $output->missing);
        self::assertSame([], $output->stale);
        self::assertSame([], $output->extra);
    }

    private function set(): GeneratedFileSet
    {
        return new GeneratedFileSet(['prometheus'], [
            new GeneratedFile('prometheus/current.yml', "same\n"),
            new GeneratedFile('prometheus/stale.yml', "new\n"),
            new GeneratedFile('prometheus/missing.yml', "new\n"),
        ]);
    }
}
