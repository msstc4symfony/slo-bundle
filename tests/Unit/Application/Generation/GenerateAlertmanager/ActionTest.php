<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerateAlertmanager;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateAlertmanager\Input;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../../../Fixtures/Generated/';

    public function testEnabledWritesTheFullConfigAndTheRoutesFragment(): void
    {
        $output = GenerationFixture::alertmanagerAction()(new Input(new Selector('showcase', ['web']), true, new AlertmanagerReceivers('slo-page', 'slo-ticket')));

        self::assertSame(['alertmanager'], $output->files->managedDirectories);
        self::assertSame(
            ['alertmanager/slo-alertmanager-routes.yml', 'alertmanager/slo-alertmanager.yml'],
            array_map(static fn (GeneratedFile $file): string => $file->relativePath, $output->files->files),
        );
        foreach ($output->files->files as $file) {
            self::assertStringEqualsFile(self::GOLDEN . $file->relativePath, $file->contents, $file->relativePath);
        }
    }

    public function testDisabledWritesNothingButStillManagesTheDirectory(): void
    {
        $output = GenerationFixture::alertmanagerAction()(new Input(new Selector('showcase', []), false, new AlertmanagerReceivers('slo-page', 'slo-ticket')));

        self::assertSame(['alertmanager'], $output->files->managedDirectories);
        self::assertSame([], $output->files->files);

        $withoutReceivers = GenerationFixture::alertmanagerAction()(new Input(new Selector('showcase', []), false, null));
        self::assertSame(['alertmanager'], $withoutReceivers->files->managedDirectories);
        self::assertSame([], $withoutReceivers->files->files);
    }

    public function testEnabledWithoutReceiversIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('receivers');

        new Input(new Selector('showcase', []), true, null);
    }
}
