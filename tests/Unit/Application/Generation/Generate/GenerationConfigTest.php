<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\Generate;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\GenerationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenerationConfigTest extends TestCase
{
    public function testKeepsValidSettings(): void
    {
        $config = new GenerationConfig('shop', ['web'], '/out', false, true, 6, true);

        self::assertSame(['shop', ['web'], '/out', false, true, 6, true], [$config->application, $config->components, $config->outputDir, $config->prometheusRules, $config->prometheusAlerts, $config->noEventsAlertHours, $config->sloth]);
        self::assertNull((new GenerationConfig(null, [], '/out'))->application);
    }

    /**
     * @return iterable<string, array{string|null, array<array-key, mixed>, string, int|null, string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'empty application' => ['', [], '/out', null, 'application'];
        yield 'empty component' => ['shop', ['web', ''], '/out', null, 'components'];
        yield 'components not a list' => ['shop', ['a' => 'web'], '/out', null, 'components'];
        yield 'non-string component' => ['shop', [1], '/out', null, 'components'];
        yield 'empty output dir' => ['shop', [], '', null, 'output_dir'];
        yield 'zero no-events hours' => ['shop', [], '/out', 0, 'no_events_alert_hours'];
    }

    /**
     * @param array<array-key, mixed> $components
     */
    #[DataProvider('provideInvalid')]
    public function testRejectsInvalidSettings(?string $application, array $components, string $outputDir, ?int $hours, string $setting): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($setting);

        // Reason: the values are outside the declared types on purpose, the constructor guards what the config tree rejects.
        // @phpstan-ignore argument.type, argument.type, argument.type
        new GenerationConfig($application, $components, $outputDir, noEventsAlertHours: $hours);
    }
}
