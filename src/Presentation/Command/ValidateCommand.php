<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Presentation\Command;

use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'slo:validate', description: 'Validate the SLO catalog against the routes; exits 1 on errors')]
final class ValidateCommand extends Command
{
    public function __construct(private readonly Action $action)
    {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = ($this->action)();

        foreach ($result->errors as $message) {
            $output->writeln('ERROR   ' . OutputFormatter::escape($message));
        }
        foreach ($result->warnings as $message) {
            $output->writeln('WARNING ' . OutputFormatter::escape($message));
        }
        foreach ($result->notices as $message) {
            $output->writeln('NOTICE  ' . OutputFormatter::escape($message));
        }

        if (!$result->isValid()) {
            $output->writeln(\sprintf('%d error(s)', \count($result->errors)));

            return Command::FAILURE;
        }

        $output->writeln('OK');

        return Command::SUCCESS;
    }
}
