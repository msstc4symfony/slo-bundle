<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Filesystem;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Infrastructure\Filesystem\FileWriter;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class FileWriterTest extends TestCase
{
    private string $root;

    #[Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/msstc4symfony-slo-file-writer-' . getmypid() . '/out';
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove(\dirname($this->root));
    }

    public function testWritesCreatingDirectoriesAndReadsBack(): void
    {
        $writer = new FileWriter();

        self::assertNull($writer->read($this->root, 'prometheus/a.yml'));
        $writer->write($this->root, new GeneratedFile('prometheus/a.yml', "a\n"));
        $writer->write($this->root, new GeneratedFile('prometheus/a.yml', "b\n"));

        self::assertSame("b\n", $writer->read($this->root, 'prometheus/a.yml'));
        self::assertSame([], glob($this->root . '/prometheus/.*.tmp*'));
    }

    public function testListsOnlyTheFilesDirectlyInTheDirectorySorted(): void
    {
        $writer = new FileWriter();
        $writer->write($this->root, new GeneratedFile('grafana/dashboards/b.json', '{}'));
        $writer->write($this->root, new GeneratedFile('grafana/provisioning/slo.yaml', 'x'));
        $writer->write($this->root, new GeneratedFile('grafana/a.json', '{}'));
        $writer->write($this->root, new GeneratedFile('prometheus/x.yml', 'x'));

        $writer->write($this->root, new GeneratedFile('grafana/0.json', '{}'));

        self::assertSame(['grafana/0.json', 'grafana/a.json'], $writer->list($this->root, 'grafana'));
        self::assertSame(['grafana/dashboards/b.json'], $writer->list($this->root, 'grafana/dashboards'));
        self::assertSame([], $writer->list($this->root, 'sloth'));
    }

    public function testDeletesAFile(): void
    {
        $writer = new FileWriter();
        $writer->write($this->root, new GeneratedFile('prometheus/x.yml', 'x'));

        $writer->delete($this->root, 'prometheus/x.yml');

        self::assertNull($writer->read($this->root, 'prometheus/x.yml'));
        self::assertSame([], $writer->list($this->root, 'prometheus'));
    }

    public function testAFailedWriteThrowsWithThePath(): void
    {
        $writer = new FileWriter();
        $writer->write($this->root, new GeneratedFile('prometheus', 'a file where a directory is needed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prometheus/x.yml');

        $writer->write($this->root, new GeneratedFile('prometheus/x.yml', 'x'));
    }
}
