<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

/**
 * Stands for a Symfony 7.4 command that overrides getDefaultName() (removed in 8.0). It does not extend Command
 * because Rector would turn the override into #[AsCommand].
 */
final class LegacyNamedCommand
{
    public function __invoke(): int
    {
        return 0;
    }

    public static function getDefaultName(): string
    {
        return 'app:legacy|app:old';
    }
}
