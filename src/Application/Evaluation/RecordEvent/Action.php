<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent;

use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Fail-open: nothing thrown while classifying or writing reaches the caller.
 */
#[WithMonologChannel('slo_bundle')]
final class Action implements ResetInterface
{
    /** @var array<string, Revision|null> "<slo>|<kind>" => revision when the objective is written by this bundle, null otherwise (also after a resolution failure) */
    private array $resolved = [];

    /** @var array<non-empty-string, true> */
    private array $warnedConflicts = [];

    public function __construct(
        private readonly SloCatalogInterface $catalog,
        private readonly MetricBindingResolver $resolver,
        private readonly RevisionCalculator $revisions,
        private readonly EventClassifier $classifier,
        private readonly EventWriterInterface $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Input $input): void
    {
        try {
            $this->warnAboutConflicts($input);
        } catch (Throwable $e) {
            $this->warn('SLO conflict lookup failed.', ['point' => $input->point->toString(), 'exception' => $e]);
        }

        try {
            foreach ($this->catalog->forPoint($input->point) as $definition) {
                $this->record($definition, $input);
            }
        } catch (Throwable $e) {
            $this->warn('SLO event was not recorded.', ['point' => $input->point->toString(), 'exception' => $e]);
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->resolved = [];
        $this->warnedConflicts = [];
    }

    private function record(SloDefinition $definition, Input $input): void
    {
        foreach ($definition->objectives() as $objective) {
            try {
                $revision = $this->revisionIfDedicated($definition, $objective, $input);
                if (!$revision instanceof Revision) {
                    continue;
                }
                $result = $this->classifier->classify($definition, $objective, $input->observation);
                if ($result instanceof EventResult) {
                    $this->writer->write($definition->name(), $objective->kind, $revision, $result);
                }
            } catch (Throwable $e) {
                $this->warn('SLO event was not recorded.', ['slo' => $definition->name()->value, 'point' => $input->point->toString(), 'exception' => $e]);
            }
        }
    }

    private function revisionIfDedicated(SloDefinition $definition, Objective $objective, Input $input): ?Revision
    {
        $key = $definition->name()->value . '|' . $objective->kind->value;
        if (\array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        // A failed resolution (e.g. a broken router) is memoised as "skip" so it is logged once, not per event.
        $this->resolved[$key] = null;
        try {
            $this->resolved[$key] = $this->resolver->isDedicated($definition, $objective->kind)
                ? $this->revisions->revision($definition, $objective->kind)
                : null;
        } catch (Throwable $e) {
            $this->warn('SLO objective could not be resolved, its events are skipped until reset.', [
                'slo' => $definition->name()->value,
                'objective' => $objective->kind->value,
                'point' => $input->point->toString(),
                'exception' => $e,
            ]);
        }

        return $this->resolved[$key];
    }

    private function warnAboutConflicts(Input $input): void
    {
        foreach ($this->catalog->conflictedNamesFor($input->point) as $name) {
            if (isset($this->warnedConflicts[$name])) {
                continue;
            }
            $this->warnedConflicts[$name] = true;
            $this->warn('SLO declarations conflict, no events are recorded for the name.', ['slo' => $name, 'point' => $input->point->toString()]);
        }
    }

    /**
     * @param array<string, string|Throwable> $context
     */
    private function warn(string $message, array $context): void
    {
        try {
            $this->logger->warning($message, $context);
        } catch (Throwable) {
            // A failing logger must not break fail-open recording.
        }
    }
}
