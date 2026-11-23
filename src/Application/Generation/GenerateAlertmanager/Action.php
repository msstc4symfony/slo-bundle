<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateAlertmanager;

use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;
use Msstc4Symfony\SloBundle\Application\Port\AlertmanagerFileBuilderInterface;

/**
 * @internal
 */
final readonly class Action
{
    public const DIRECTORY = 'alertmanager';

    public const FULL_FILE = self::DIRECTORY . '/slo-alertmanager.yml';

    public const ROUTES_FILE = self::DIRECTORY . '/slo-alertmanager-routes.yml';

    public function __construct(
        private AlertmanagerFileBuilderInterface $builder,
    ) {
    }

    public function __invoke(Input $input): Output
    {
        $receivers = $input->receivers;
        // The directory stays managed when disabled, so files of an earlier enabled run are deleted.
        if (!$input->enabled || !$receivers instanceof AlertmanagerReceivers) {
            return new Output(new GeneratedFileSet([self::DIRECTORY], []));
        }

        return new Output(new GeneratedFileSet([self::DIRECTORY], [
            new GeneratedFile(self::FULL_FILE, $this->builder->full($input->selector, $receivers)),
            new GeneratedFile(self::ROUTES_FILE, $this->builder->routes($input->selector, $receivers)),
        ]));
    }
}
