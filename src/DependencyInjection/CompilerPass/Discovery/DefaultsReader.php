<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Discovery\DefinitionFactory;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Reads the msstc4symfony_slo.defaults.* parameters strictly and validates them once, whether or not a point uses them:
 * a silent fallback would let HTTP availability miss its bad statuses.
 *
 * @internal
 */
final class DefaultsReader
{
    private const PREFIX = 'msstc4symfony_slo.';

    /**
     * @throws InvalidConfigurationException
     */
    public static function factory(ContainerBuilder $container): DefinitionFactory
    {
        $window = self::string($container, 'defaults.window');
        $bad = self::statuses($container, 'defaults.http.bad_statuses');
        $ineligible = self::statuses($container, 'defaults.http.ineligible_statuses');
        $expectedFailureIsGood = self::bool($container, 'defaults.logic.expected_failure_is_good');
        $countAttempts = self::bool($container, 'defaults.messenger.count_attempts');

        try {
            Window::fromString($window);
            new Classification(StatusSet::fromList($bad), StatusSet::fromList($ineligible), $expectedFailureIsGood, $countAttempts);
        } catch (InvalidArgumentException $e) {
            throw new InvalidConfigurationException(self::PREFIX . 'defaults: ' . $e->getMessage(), 0, $e);
        }

        return new DefinitionFactory($window, $bad, $ineligible, $expectedFailureIsGood, $countAttempts);
    }

    private static function string(ContainerBuilder $container, string $path): string
    {
        $value = self::value($container, $path);

        return \is_string($value) ? $value : throw self::invalid($path, 'a string');
    }

    private static function bool(ContainerBuilder $container, string $path): bool
    {
        $value = self::value($container, $path);

        return \is_bool($value) ? $value : throw self::invalid($path, 'a boolean');
    }

    /**
     * @return list<int|string>
     */
    private static function statuses(ContainerBuilder $container, string $path): array
    {
        $value = self::value($container, $path);
        if (!\is_array($value) || !array_is_list($value)) {
            throw self::invalid($path, 'a list of status codes, classes and ranges');
        }
        $statuses = [];
        foreach ($value as $status) {
            $statuses[] = \is_int($status) || \is_string($status) ? $status : throw self::invalid($path, 'a list of status codes, classes and ranges');
        }

        return $statuses;
    }

    private static function value(ContainerBuilder $container, string $path): mixed
    {
        $name = self::PREFIX . $path;

        return $container->hasParameter($name) ? $container->getParameterBag()->resolveValue($container->getParameter($name)) : null;
    }

    private static function invalid(string $path, string $expected): InvalidConfigurationException
    {
        return new InvalidConfigurationException(\sprintf('Parameter "%s%s" is missing or not %s.', self::PREFIX, $path, $expected));
    }
}
