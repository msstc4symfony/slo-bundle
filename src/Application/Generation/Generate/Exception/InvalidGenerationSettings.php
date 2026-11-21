<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception;

use InvalidArgumentException;

/**
 * @internal
 */
final class InvalidGenerationSettings extends InvalidArgumentException
{
    public static function missingApplication(): self
    {
        return new self('Set msstc4symfony_slo.generation.selector.application to the "application" label of your metrics: slo:generate never reads it from the runtime environment.');
    }

    public static function invalidOutputDirectory(): self
    {
        return new self('The output directory must be a non-empty path other than "/".');
    }
}
