<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsCommand('app:sync-partners')]
#[AsPeriodicTask(frequency: 3600, arguments: ['--full' => true])]
#[Slo('partners.sync', [new Availability(99.5)], deadline: '10m')]
final class SyncPartnersCommand extends Command
{
    #[Override]
    protected function configure(): void
    {
        $this->addOption('full', null, InputOption::VALUE_NONE);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
