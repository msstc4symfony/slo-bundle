<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\Generate;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception\InvalidGenerationSettings;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\GenerationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class GenerationConfigTest extends TestCase
{
    public function testKeepsValidSettings(): void
    {
        $config = new GenerationConfig('shop', ['web'], '/out', false, true, 6, true);

        self::assertSame(['shop', ['web'], '/out', false, true, 6, true], [$config->application, $config->components, $config->outputDir, $config->prometheusRules, $config->prometheusAlerts, $config->noEventsAlertHours, $config->sloth]);
        self::assertNull((new GenerationConfig(null, [], '/out'))->application);
        self::assertNull((new GenerationConfig('', [], '/out'))->application, 'an env placeholder resolved to "" is not configured');
    }

    /**
     * @return iterable<string, array{string|null, array<array-key, mixed>, string, int|null, string}>
     */
    public static function provideInvalid(): iterable
    {
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
        // @phpstan-ignore argument.type, argument.type
        new GenerationConfig($application, $components, $outputDir, noEventsAlertHours: $hours);
    }

    public function testAlertmanagerIsOffByDefaultWithDefaultReceivers(): void
    {
        $config = new GenerationConfig('shop', [], '/out');

        self::assertFalse($config->alertmanager);
        self::assertEquals(new AlertmanagerReceivers('slo-page', 'slo-ticket'), $config->alertmanagerReceivers(null, null));
    }

    public function testReceiverOverridesWin(): void
    {
        $config = new GenerationConfig('shop', [], '/out', alertmanager: true, pageReceiver: 'a', ticketReceiver: 'b');

        self::assertEquals(new AlertmanagerReceivers('a', 'b'), $config->alertmanagerReceivers(null, null));
        self::assertEquals(new AlertmanagerReceivers('x', 'b'), $config->alertmanagerReceivers('x', null));
        self::assertEquals(new AlertmanagerReceivers('a', 'y'), $config->alertmanagerReceivers(null, 'y'));
    }

    #[TestWith(['', null])]
    #[TestWith([null, ''])]
    #[TestWith(['bad name', null])]
    public function testEmptyOrInvalidReceiverIsRejected(?string $page, ?string $ticket): void
    {
        $config = new GenerationConfig('shop', [], '/out', alertmanager: true);

        $this->expectException(InvalidGenerationSettings::class);
        $config->alertmanagerReceivers($page, $ticket);
    }

    public function testEnvResolvedEmptyConfiguredReceiverIsRejected(): void
    {
        $config = new GenerationConfig('shop', [], '/out', alertmanager: true, pageReceiver: '');

        $this->expectException(InvalidGenerationSettings::class);
        $config->alertmanagerReceivers(null, null);
    }
}
