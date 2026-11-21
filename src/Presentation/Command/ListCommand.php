<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Presentation\Command;

use JsonException;
use Msstc4Symfony\SloBundle\Application\Catalog\List\Action;
use Msstc4Symfony\SloBundle\Application\Catalog\List\ObjectiveRow;
use Msstc4Symfony\SloBundle\Application\Catalog\List\PointRow;
use Msstc4Symfony\SloBundle\Application\Catalog\List\Row;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'slo:list', description: 'List the declared SLOs with their points, routes and revisions')]
final class ListCommand extends Command
{
    private const FORMATS = ['text', 'json'];

    public function __construct(private readonly Action $action)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!\is_string($format) || !\in_array($format, self::FORMATS, true)) {
            $output->writeln('<error>Unknown format, expected text or json.</error>');

            return Command::INVALID;
        }

        $rows = ($this->action)()->rows;
        if ($format === 'json') {
            try {
                $output->writeln($this->json($rows), OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $this->table($output, $rows);

        return Command::SUCCESS;
    }

    /**
     * @param list<Row> $rows
     */
    private function table(OutputInterface $output, array $rows): void
    {
        $table = new Table($output);
        $table->setHeaders(['SLO', 'Status', 'Objective', 'Target', 'Threshold', 'Window', 'Revision', 'Strategy', 'Type', 'Points']);
        foreach ($rows as $row) {
            $table->addRow(array_map(OutputFormatter::escape(...), [
                $row->name,
                $this->status($row),
                $this->lines($row->objectives, static fn (ObjectiveRow $o): string => $o->kind),
                $this->lines($row->objectives, static fn (ObjectiveRow $o): string => $o->target . '%'),
                $this->lines($row->objectives, static fn (ObjectiveRow $o): string => $o->threshold ?? '-'),
                $row->window,
                $this->lines($row->objectives, static fn (ObjectiveRow $o): string => $o->revision),
                $this->lines($row->objectives, static fn (ObjectiveRow $o): string => $o->strategy ?? '-'),
                $row->pointType,
                $this->lines($row->points, static fn (PointRow $p): string => $p->routes === [] ? $p->point : $p->point . ' -> ' . implode(', ', $p->routes)),
            ]));
        }
        $table->render();
    }

    private function status(Row $row): string
    {
        if ($row->status === Row::STATUS_DUPLICATE) {
            return 'DUPLICATE';
        }

        return $row->sharedPoints === [] ? 'OK' : \sprintf('shared by %d points', \count($row->sharedPoints));
    }

    /**
     * @template T of object
     *
     * @param list<T> $items
     * @param callable(T): string $render
     */
    private function lines(array $items, callable $render): string
    {
        return implode("\n", array_map($render, $items));
    }

    /**
     * @param list<Row> $rows
     *
     * @throws JsonException
     */
    private function json(array $rows): string
    {
        $data = array_map(static fn (Row $row): array => [
            'name' => $row->name,
            'status' => $row->status,
            'point_type' => $row->pointType,
            'window' => $row->window,
            'objectives' => array_map(static fn (ObjectiveRow $o): array => [
                'kind' => $o->kind,
                'target' => $o->target,
                'threshold' => $o->threshold,
                'revision' => $o->revision,
                'strategy' => $o->strategy,
            ], $row->objectives),
            'points' => array_map(static fn (PointRow $p): array => ['point' => $p->point, 'routes' => $p->routes], $row->points),
            'shared_points' => $row->sharedPoints,
        ], $rows);

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
