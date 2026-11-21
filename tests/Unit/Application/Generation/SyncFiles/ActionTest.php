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
    private const DISK = [
        '/out/prometheus/slo-current.rules.yml' => "same\n",
        '/out/prometheus/slo-stale.rules.yml' => "old\n",
        '/out/prometheus/slo-old.rules.yml' => "removed slo\n",
        '/out/prometheus/prometheus.yml' => "hand-written\n",
        '/out/prometheus/.gitkeep' => '',
        '/out/prometheus/nested/x.yml' => "x\n",
        '/out/prometheus/nested/slo-x.rules.yml' => "x\n",
        '/out/unmanaged/slo-keep.rules.yml' => "x\n",
    ];

    public function testCheckReportsStaleMissingAndExtraOwnedFilesWithoutTouchingTheDisk(): void
    {
        $writer = new InMemoryFileWriter(self::DISK);

        $output = (new Action($writer))(new Input($this->set(), '/out', true));

        self::assertFalse($output->isUpToDate());
        self::assertSame(['prometheus/slo-stale.rules.yml'], $output->stale);
        self::assertSame(['prometheus/slo-missing.rules.yml'], $output->missing);
        self::assertSame(['prometheus/slo-old.rules.yml'], $output->extra);
        self::assertSame(self::DISK, $writer->files);
    }

    public function testWriteUpdatesOwnedFilesAndNeverTouchesForeignOnes(): void
    {
        $writer = new InMemoryFileWriter(self::DISK);

        $output = (new Action($writer))(new Input($this->set(), '/out', false));

        self::assertSame(['prometheus/slo-old.rules.yml'], $output->extra);
        $expected = self::DISK;
        unset($expected['/out/prometheus/slo-old.rules.yml']);
        $expected['/out/prometheus/slo-stale.rules.yml'] = "new\n";
        $expected['/out/prometheus/slo-missing.rules.yml'] = "new\n";
        ksort($expected);
        self::assertSame($expected, $writer->files);
        self::assertTrue((new Action($writer))(new Input($this->set(), '/out', true))->isUpToDate());
    }

    public function testEmptyOutputDirectoryIsAllMissing(): void
    {
        $output = (new Action(new InMemoryFileWriter()))(new Input($this->set(), '/out', true));

        self::assertSame(['prometheus/slo-current.rules.yml', 'prometheus/slo-missing.rules.yml', 'prometheus/slo-stale.rules.yml'], $output->missing);
        self::assertSame([], $output->stale);
        self::assertSame([], $output->extra);
    }

    private function set(): GeneratedFileSet
    {
        return new GeneratedFileSet(['prometheus'], [
            new GeneratedFile('prometheus/slo-current.rules.yml', "same\n"),
            new GeneratedFile('prometheus/slo-stale.rules.yml', "new\n"),
            new GeneratedFile('prometheus/slo-missing.rules.yml', "new\n"),
        ]);
    }
}
