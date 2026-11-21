<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Promtool;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Validates the generated rules with the real promtool (ruling 10): never skipped, a missing Docker is a failure.
 */
#[Group('promtool')]
final class PromtoolTest extends TestCase
{
    private const IMAGE = 'prom/prometheus:v3.15.0';

    private const FIXTURES = __DIR__ . '/../Fixtures';

    private string $scratch;

    #[Override]
    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/msstc4symfony-slo-promtool-' . getmypid();
        $this->assertDockerIsAvailable();
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->scratch);
    }

    public function testGoldenRuleFilesPassCheckRules(): void
    {
        $this->promtool(self::FIXTURES, ['check', 'rules', 'Generated/prometheus/slo-recording.rules.yml', 'Generated/prometheus/slo-alerts.rules.yml']);
    }

    public function testRulesWithTheNoEventsAlertPassCheckRules(): void
    {
        $output = GenerationFixture::action(GenerationFixture::catalog())(GenerationFixture::input(6));
        $paths = array_map(function (GeneratedFile $file): string {
            $path = $this->scratch . '/' . $file->relativePath;
            (new Filesystem())->dumpFile($path, $file->contents);
            chmod($path, 0o644);

            return $file->relativePath;
        }, $output->files->files);
        chmod($this->scratch, 0o755);
        chmod($this->scratch . '/prometheus', 0o755);

        $this->promtool($this->scratch, ['check', 'rules', ...$paths]);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function ruleTestProvider(): iterable
    {
        yield 'no series: no SLI, no alert' => ['no-data.test.yml'];
        yield '5 % bad over 1h at 99.9: page alert' => ['fast-burn.test.yml'];
        yield 'old revision only: no ratio for the new one' => ['revision.test.yml'];
        yield '2 days of a 30d window: warming up, no ticket' => ['warming-up.test.yml'];
    }

    /**
     * @param non-empty-string $file
     */
    #[DataProvider('ruleTestProvider')]
    public function testRuleUnitTests(string $file): void
    {
        $this->promtool(self::FIXTURES, ['test', 'rules', 'Promtool/' . $file]);
    }

    private function assertDockerIsAvailable(): void
    {
        [$exit, $output] = $this->execute(['docker', 'version', '--format', '{{.Server.Version}}']);
        if ($exit !== 0) {
            self::fail(\sprintf("Docker is required for the promtool tests (they are never skipped); `docker version` exited with %d:\n%s", $exit, $output));
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function promtool(string $mount, array $arguments): void
    {
        $command = ['docker', 'run', '--rm', '-v', realpath($mount) . ':/work:ro', '-w', '/work', '--entrypoint', 'promtool', self::IMAGE, ...$arguments];
        [$exit, $output] = $this->execute($command);

        self::assertSame(0, $exit, \sprintf("Docker run of promtool %s failed (exit %d):\n%s", implode(' ', $arguments), $exit, $output));
    }

    /**
     * @param non-empty-list<string> $command
     *
     * @return array{int, string}
     */
    private function execute(array $command): array
    {
        // stderr goes to a file: reading two pipes one after the other can deadlock.
        (new Filesystem())->mkdir($this->scratch);
        $stderr = $this->scratch . '/stderr.log';
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes);
        if ($process === false) {
            return [127, \sprintf('cannot start %s', $command[0])];
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        return [$exit, ($output === false ? '' : $output) . @file_get_contents($stderr)];
    }
}
