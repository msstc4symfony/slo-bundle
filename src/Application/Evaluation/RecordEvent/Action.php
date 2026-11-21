<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Logging\WarnOnceLimiter;
use Msstc4Symfony\SloBundle\Application\Port\EventRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Application\Port\PrecomputedRevisionsInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Fail-open: nothing thrown while classifying or writing reaches the caller.
 *
 * @internal bridges use EventRecorderInterface
 */
final class Action implements EventRecorderInterface, ResetInterface
{
    /** @var array<string, Revision|null> "<slo>|<kind>" => revision when the objective is written by this bundle, null otherwise (also after a resolution failure) */
    private array $resolved = [];

    /** @var array<string, true> resolutions that failed: retried after reset, successful ones are kept (routes and catalog are fixed per container) */
    private array $failedResolutions = [];

    /** @var array<non-empty-string, true> */
    private array $warnedConflicts = [];

    /** keyed "<slo>|<kind>|<exception class>", forgotten on reset */
    private readonly WarnOnceLimiter $failureWarnings;

    /** keyed like the failures but never reset: a deterministic resolution failure is retried after reset, logged once */
    private readonly WarnOnceLimiter $resolutionWarnings;

    public function __construct(
        private readonly SloCatalogInterface $catalog,
        private readonly MetricBindingResolver $resolver,
        private readonly RevisionResolver $revisions,
        private readonly EventClassifier $classifier,
        private readonly EventWriterInterface $writer,
        private readonly LoggerInterface $logger,
        private readonly PrecomputedRevisionsInterface $precomputed,
    ) {
        $this->failureWarnings = new WarnOnceLimiter($logger, 'Further SLO recording failures are suppressed until reset.');
        $this->resolutionWarnings = new WarnOnceLimiter($logger, 'Further SLO resolution failures are not logged.');
    }

    #[Override]
    public function record(Input $input): void
    {
        try {
            $this->warnAboutConflicts($input);
        } catch (Throwable $e) {
            $this->logger->warning('SLO conflict lookup failed.', ['point' => $input->point->toString(), 'exception' => $e]);
        }

        try {
            foreach ($this->catalog->forPoint($input->point) as $definition) {
                $this->recordDefinition($definition, $input);
            }
        } catch (Throwable $e) {
            $this->logger->warning('SLO event was not recorded.', ['point' => $input->point->toString(), 'exception' => $e]);
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->resolved = array_diff_key($this->resolved, $this->failedResolutions);
        $this->failedResolutions = [];
        $this->warnedConflicts = [];
        $this->failureWarnings->reset();
    }

    private function recordDefinition(SloDefinition $definition, Input $input): void
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
                $this->warnFailure($definition, $objective, $input, $e);
            }
        }
    }

    private function revisionIfDedicated(SloDefinition $definition, Objective $objective, Input $input): ?Revision
    {
        $key = $this->objectiveKey($definition, $objective);
        if (\array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        // A failed resolution (e.g. a broken router) is memoised as "skip" so it is logged once, not per event.
        $this->resolved[$key] = null;
        try {
            $this->resolved[$key] = $this->resolve($definition, $objective);
        } catch (Throwable $e) {
            $this->failedResolutions[$key] = true;
            $this->resolutionWarnings->warn($key . '|' . $e::class, 'SLO objective could not be resolved, its events are skipped until reset; repeats are not logged.', [
                'slo' => $definition->name()->value,
                'objective' => $objective->kind->value,
                'point' => $input->point->toString(),
                'exception' => $e,
            ]);
        }

        return $this->resolved[$key];
    }

    private function resolve(SloDefinition $definition, Objective $objective): ?Revision
    {
        $precomputed = $this->precomputed->revisionOf($definition->name(), $objective->kind);
        if ($precomputed !== null) {
            return $precomputed === false ? null : $precomputed;
        }

        return $this->resolver->isDedicated($definition, $objective->kind)
            ? $this->revisions->revision($definition, $objective->kind)
            : null;
    }

    /**
     * One warning per SLO, objective and exception class until reset: a storage outage would otherwise log every event.
     */
    private function warnFailure(SloDefinition $definition, Objective $objective, Input $input, Throwable $e): void
    {
        $this->failureWarnings->warn($this->objectiveKey($definition, $objective) . '|' . $e::class, 'SLO event was not recorded, repeats of this failure are not logged until reset.', [
            'slo' => $definition->name()->value,
            'objective' => $objective->kind->value,
            'point' => $input->point->toString(),
            'exception' => $e,
        ]);
    }

    private function objectiveKey(SloDefinition $definition, Objective $objective): string
    {
        return $definition->name()->value . '|' . $objective->kind->value;
    }

    private function warnAboutConflicts(Input $input): void
    {
        foreach ($this->catalog->conflictedNamesFor($input->point) as $name) {
            if (isset($this->warnedConflicts[$name])) {
                continue;
            }
            $this->warnedConflicts[$name] = true;
            $this->logger->warning('SLO declarations conflict, no events are recorded for the name.', ['slo' => $name, 'point' => $input->point->toString()]);
        }
    }
}
