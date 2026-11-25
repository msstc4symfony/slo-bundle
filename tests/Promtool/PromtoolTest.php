<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Promtool;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Input as SlothInput;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates the generated rules with the real promtool (ruling 10): never skipped, a missing Docker is a failure.
 */
#[Group('promtool')]
final class PromtoolTest extends TestCase
{
    private const IMAGE = 'prom/prometheus:v3.15.0';

    private const SLOTH_IMAGE = 'ghcr.io/slok/sloth:v0.16.0';

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

        $this->promtool($this->scratch, ['check', 'rules', ...$this->dump($output->files->files)]);
    }

    public function testATaskSloOnSeveralPointsCountsTheUnfinishedRunsOfEveryTask(): void
    {
        $output = GenerationFixture::action(GenerationFixture::multiPointTaskCatalog())(GenerationFixture::input());
        $this->dump($output->files->files);
        (new Filesystem())->copy(self::FIXTURES . '/Promtool/scheduled-task-multi-point.test.yml', $this->scratch . '/scheduled-task-multi-point.test.yml');
        chmod($this->scratch . '/scheduled-task-multi-point.test.yml', 0o644);

        $this->promtool($this->scratch, ['test', 'rules', 'scheduled-task-multi-point.test.yml']);
    }

    public function testSlothQueriesOfTasksAgreeWithTheRecordingRules(): void
    {
        $output = GenerationFixture::slothAction(GenerationFixture::catalog())(new SlothInput(new Selector('shop', ['worker', 'web'])));
        $rules = [];
        foreach ($output->files->files as $file) {
            if (!\in_array($file->relativePath, ['sloth/slo-cleanup.run.yml', 'sloth/slo-partners.sync.yml'], true)) {
                continue;
            }
            $spec = Yaml::parse($file->contents);
            self::assertIsArray($spec);
            self::assertIsArray($spec['slos']);
            foreach ($spec['slos'] as $slo) {
                self::assertIsArray($slo);
                self::assertIsArray($slo['labels']);
                self::assertIsArray($slo['sli']);
                self::assertIsArray($slo['sli']['events']);
                self::assertIsString($slo['sli']['events']['error_query']);
                self::assertIsString($slo['sli']['events']['total_query']);
                foreach (['5m', '30m', '1h'] as $window) {
                    $rules[] = [
                        'record' => 'sloth:ratio:rate' . $window,
                        'expr' => str_replace('{{.window}}', $window, \sprintf('(%s) / (%s)', $slo['sli']['events']['error_query'], $slo['sli']['events']['total_query'])),
                        'labels' => ['objective' => $slo['labels']['objective'], 'slo' => $slo['labels']['slo']],
                    ];
                }
            }
        }
        self::assertCount(9, $rules);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->scratch . '/sloth-queries.rules.yml', Yaml::dump(['groups' => [['name' => 'sloth-queries', 'interval' => '1m', 'rules' => $rules]]], 6));
        $filesystem->copy(self::FIXTURES . '/Promtool/scheduled-task-sloth.test.yml', $this->scratch . '/scheduled-task-sloth.test.yml');
        chmod($this->scratch, 0o755);
        chmod($this->scratch . '/sloth-queries.rules.yml', 0o644);
        chmod($this->scratch . '/scheduled-task-sloth.test.yml', 0o644);

        $this->promtool($this->scratch, ['test', 'rules', 'scheduled-task-sloth.test.yml']);
    }

    public function testGeneratedSlothSpecsPassSlothValidate(): void
    {
        $output = GenerationFixture::slothAction(GenerationFixture::catalog())(new SlothInput(new Selector('shop', ['worker', 'web'])));
        self::assertCount(5, $output->files->files);
        foreach ($output->files->files as $file) {
            self::assertNotSame('', $file->contents);
            $path = $this->scratch . '/' . $file->relativePath;
            (new Filesystem())->dumpFile($path, $file->contents);
            chmod($path, 0o644);
        }
        chmod($this->scratch, 0o755);
        chmod($this->scratch . '/sloth', 0o755);

        [$exit, $log] = $this->execute(['docker', 'run', '--rm', '-v', $this->scratch . '/sloth:/specs:ro', self::SLOTH_IMAGE, 'validate', '-i', '/specs']);
        self::assertSame(0, $exit, \sprintf("sloth validate failed (exit %d):\n%s", $exit, $log));
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
        yield 'Strategy A latency reads the Prometheus 3 le="1.0" bucket' => ['latency-bucket.test.yml'];
        yield 'scheduled task: unfinished runs are bad events, healthy and retried runs are not' => ['scheduled-task.test.yml'];
        yield 'scheduled task: resets, outages and replica churn never report unfinished runs' => ['scheduled-task-resilience.test.yml'];
    }

    /**
     * @param non-empty-string $file
     */
    #[DataProvider('ruleTestProvider')]
    public function testRuleUnitTests(string $file): void
    {
        $this->promtool(self::FIXTURES, ['test', 'rules', 'Promtool/' . $file]);
    }

    /**
     * @param list<GeneratedFile> $files
     *
     * @return list<non-empty-string> the relative paths
     */
    private function dump(array $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            $path = $this->scratch . '/' . $file->relativePath;
            (new Filesystem())->dumpFile($path, $file->contents);
            chmod($path, 0o644);
            $paths[] = $file->relativePath;
        }
        chmod($this->scratch, 0o755);
        chmod($this->scratch . '/prometheus', 0o755);

        return $paths;
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
