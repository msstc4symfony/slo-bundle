<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Amtool;

use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateAlertmanager\Input;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates the generated Alertmanager files with the real amtool: never skipped, a missing Docker is a failure.
 */
#[Group('amtool')]
final class AmtoolTest extends TestCase
{
    private const IMAGE = 'prom/alertmanager:v0.34.0';

    private string $scratch;

    #[Override]
    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/msstc4symfony-slo-amtool-' . getmypid();
        $this->assertDockerIsAvailable();
        $output = GenerationFixture::alertmanagerAction()(new Input(new Selector('showcase', ['web']), true, new AlertmanagerReceivers('slo-page', 'slo-ticket')));
        foreach ($output->files->files as $file) {
            $this->write($file);
        }
        chmod($this->scratch, 0o755);
        chmod($this->scratch . '/alertmanager', 0o755);
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->scratch);
    }

    public function testFullConfigPassesCheckConfig(): void
    {
        [$exit, $output] = $this->amtool(['check-config', 'alertmanager/slo-alertmanager.yml']);

        self::assertSame(0, $exit, $output);
    }

    public function testFullConfigWithOneReceiverForBothSeveritiesPassesCheckConfig(): void
    {
        $this->writeFull('ops', 'ops', 'alertmanager/ops.yml');

        [$exit, $output] = $this->amtool(['check-config', 'alertmanager/ops.yml']);

        self::assertSame(0, $exit, $output);
    }

    #[DataProvider('severityProvider')]
    public function testOneReceiverForBothSeveritiesRoutesBothToIt(string $severity): void
    {
        $this->writeFull('ops', 'ops', 'alertmanager/ops.yml');

        [$exit, $output] = $this->amtool(['config', 'routes', 'test', '--config.file=alertmanager/ops.yml', 'alertname=SloErrorBudgetBurn', 'severity=' . $severity, 'application=showcase']);

        self::assertSame(0, $exit, $output);
        self::assertSame('ops', trim($output));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'page' => ['page'];
        yield 'ticket' => ['ticket'];
    }

    public function testFragmentMergedIntoAUserConfigPassesCheckConfig(): void
    {
        $this->writeMergedConfig();

        [$exit, $output] = $this->amtool(['check-config', 'alertmanager/merged.yml']);

        self::assertSame(0, $exit, $output);
    }

    /**
     * @return iterable<string, array{string, list<non-empty-string>, non-empty-string}>
     */
    public static function routingProvider(): iterable
    {
        yield 'page burn' => ['alertmanager/slo-alertmanager.yml', ['alertname=SloErrorBudgetBurn', 'severity=page', 'application=showcase'], 'slo-page'];
        yield 'ticket burn' => ['alertmanager/slo-alertmanager.yml', ['alertname=SloErrorBudgetBurn', 'severity=ticket', 'application=showcase'], 'slo-ticket'];
        yield 'no events' => ['alertmanager/slo-alertmanager.yml', ['alertname=SloNoEvents', 'severity=ticket', 'application=showcase'], 'slo-ticket'];
        yield 'other alert falls to the root' => ['alertmanager/slo-alertmanager.yml', ['alertname=Other'], 'slo-unrouted'];
        yield 'merged: page burn' => ['alertmanager/merged.yml', ['alertname=SloErrorBudgetBurn', 'severity=page', 'application=showcase'], 'slo-page'];
        yield 'merged: ticket burn' => ['alertmanager/merged.yml', ['alertname=SloErrorBudgetBurn', 'severity=ticket', 'application=showcase'], 'slo-ticket'];
        yield 'merged: no events' => ['alertmanager/merged.yml', ['alertname=SloNoEvents', 'severity=ticket', 'application=showcase'], 'slo-ticket'];
        yield 'merged: other application is not routed by the fragment' => ['alertmanager/merged.yml', ['alertname=SloNoEvents', 'severity=ticket', 'application=other'], 'default'];
        yield 'merged: other alert falls to the user root' => ['alertmanager/merged.yml', ['alertname=Other'], 'default'];
    }

    /**
     * @param non-empty-string $config
     * @param list<non-empty-string> $labels
     * @param non-empty-string $receiver
     */
    #[DataProvider('routingProvider')]
    public function testRoutesTest(string $config, array $labels, string $receiver): void
    {
        $this->writeMergedConfig();

        [$exit, $output] = $this->amtool(['config', 'routes', 'test', '--config.file=' . $config, ...$labels]);

        self::assertSame(0, $exit, $output);
        self::assertSame($receiver, trim($output));
    }

    /**
     * @param non-empty-string $page
     * @param non-empty-string $ticket
     * @param non-empty-string $relativePath
     */
    private function writeFull(string $page, string $ticket, string $relativePath): void
    {
        $output = GenerationFixture::alertmanagerAction()(new Input(new Selector('showcase', ['web']), true, new AlertmanagerReceivers($page, $ticket)));
        foreach ($output->files->files as $file) {
            if ($file->relativePath === 'alertmanager/slo-alertmanager.yml') {
                $this->write(new GeneratedFile($relativePath, $file->contents));
            }
        }
    }

    private function writeMergedConfig(): void
    {
        $fragment = Yaml::parseFile($this->scratch . '/alertmanager/slo-alertmanager-routes.yml');
        self::assertIsArray($fragment);
        $config = [
            'route' => ['receiver' => 'default', 'routes' => $fragment['routes']],
            'receivers' => [['name' => 'default'], ['name' => 'slo-page'], ['name' => 'slo-ticket']],
            'inhibit_rules' => $fragment['inhibit_rules'],
        ];
        $path = $this->scratch . '/alertmanager/merged.yml';
        (new Filesystem())->dumpFile($path, Yaml::dump($config, 10, 2));
        chmod($path, 0o644);
    }

    private function write(GeneratedFile $file): void
    {
        $path = $this->scratch . '/' . $file->relativePath;
        (new Filesystem())->dumpFile($path, $file->contents);
        chmod($path, 0o644);
    }

    private function assertDockerIsAvailable(): void
    {
        [$exit, $output] = $this->execute(['docker', 'version', '--format', '{{.Server.Version}}']);
        if ($exit !== 0) {
            self::fail(\sprintf("Docker is required for the amtool tests (they are never skipped); `docker version` exited with %d:\n%s", $exit, $output));
        }
    }

    /**
     * @param non-empty-list<string> $arguments
     *
     * @return array{int, string}
     */
    private function amtool(array $arguments): array
    {
        return $this->execute(['docker', 'run', '--rm', '-v', $this->scratch . ':/work:ro', '-w', '/work', '--entrypoint', 'amtool', self::IMAGE, ...$arguments]);
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
