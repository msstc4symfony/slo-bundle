<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\GenerationConfig;

/**
 * @internal
 */
final class InvalidGenerationSettings extends InvalidArgumentException
{
    public static function missingApplication(): self
    {
        return new self('Set msstc4symfony_slo.generation.selector.application to the "application" label of your metrics: slo:generate never reads it from the runtime environment.');
    }

    public static function invalidLabelValue(string $setting, string $value): self
    {
        return new self(\sprintf('The %s "%s" must match %s: it becomes a label value in the generated PromQL (an env placeholder resolved to an invalid value?).', $setting, $value, GenerationConfig::LABEL_VALUE));
    }

    public static function invalidOutputDirectory(): self
    {
        return new self('The output directory must be a non-empty path other than "/".');
    }

    public static function invalidReceiver(string $severity, string $name): self
    {
        return new self(\sprintf('The %s receiver name "%s" must match %s (an env placeholder resolved to an empty or invalid value?).', $severity, $name, GenerationConfig::LABEL_VALUE));
    }

    public static function receiverOverrideWithoutAlertmanager(): self
    {
        return new self('--page-receiver and --ticket-receiver need msstc4symfony_slo.generation.alertmanager.enabled set to true.');
    }
}
