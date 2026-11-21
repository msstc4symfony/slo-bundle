<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Catalog;

use Closure;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogContents;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogDump;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Hydrates lazily: the runtime reads only the definitions of the points it sees, the CLI-only data is hydrated on the
 * first diagnostic call. A dump that cannot be read (stale cache after an upgrade) never throws on the runtime path:
 * its points record nothing and one warning is logged; diagnostics throw so the CLI reports it.
 *
 * @phpstan-import-type CatalogShape from CatalogDump
 *
 * @internal
 */
final class CompiledSloCatalog implements SloCatalogInterface, CatalogDiagnosticsInterface, ResetInterface
{
    /** @var array<non-negative-int, SloDefinition> */
    private array $hydrated = [];

    /** @var array<string, list<SloDefinition>> declared points only */
    private array $byPoint = [];

    /** @var array<string, true> points memoised without an unreadable definition: retried after reset */
    private array $partialPoints = [];

    private ?CatalogContents $contents = null;

    private ?CatalogExtras $extras = null;

    /** @var array<'contents'|'extras', RuntimeException> memoised until reset: a corrupt section is not re-hydrated on every call */
    private array $unreadable = [];

    /** @var array<non-empty-string, SloDefinition>|null */
    private ?array $byName = null;

    private bool $unreadableWarned = false;

    /**
     * @param CatalogShape $catalog
     */
    public function __construct(
        private readonly array $catalog,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function all(): array
    {
        $all = [];
        foreach ($this->contents()->definitions as $index => $definition) {
            $all[] = $this->hydrated[$index] ??= $definition;
        }

        return $all;
    }

    #[Override]
    public function get(SloName $name): ?SloDefinition
    {
        if ($this->byName === null) {
            $byName = [];
            foreach ($this->all() as $definition) {
                $byName[$definition->name()->value] = $definition;
            }
            $this->byName = $byName;
        }

        return $this->byName[$name->value] ?? null;
    }

    #[Override]
    public function forPoint(PointId $point): array
    {
        $key = $point->toString();
        if (isset($this->byPoint[$key])) {
            return $this->byPoint[$key];
        }
        // Only declared points are memoised: callers also ask for arbitrary undeclared ones (operation names, messages).
        if (!$this->ensureCurrentFormat() || !isset($this->catalog['point_index'][$key])) {
            return [];
        }

        $definitions = [];
        foreach ($this->catalog['point_index'][$key] as $index) {
            try {
                $definitions[] = $this->hydrated[$index] ??= CatalogDump::definition($this->catalog['definitions'][$index]);
            } catch (Throwable $e) {
                $this->warnUnreadable($e);
                $this->partialPoints[$key] = true;
            }
        }

        return $this->byPoint[$key] = $definitions;
    }

    #[Override]
    public function ignored(): array
    {
        return $this->contents()->ignored;
    }

    #[Override]
    public function conflictedNamesFor(PointId $point): array
    {
        return $this->ensureCurrentFormat() ? $this->catalog['conflicted_points'][$point->toString()] ?? [] : [];
    }

    #[Override]
    public function conflicts(): array
    {
        return $this->contents()->conflicts;
    }

    #[Override]
    public function shared(): array
    {
        $shared = [];
        foreach ($this->all() as $definition) {
            if (\count($definition->points()) > 1) {
                $shared[$definition->name()->value] = $definition->points();
            }
        }

        return $shared;
    }

    /**
     * Hydrates the extras section only: a corrupt definition does not hide the facts every other SLO needs.
     */
    #[Override]
    public function extras(): CatalogExtras
    {
        if ($this->contents instanceof CatalogContents) {
            return $this->contents->extras;
        }

        return $this->extras ??= $this->read('extras', fn (): CatalogExtras => CatalogDump::extrasFromArray($this->catalog['extras']));
    }

    #[Override]
    public function hasHttpPoints(): bool
    {
        return $this->ensureCurrentFormat() && $this->catalog['extras']['has_http_points'];
    }

    #[Override]
    public function failureTransports(): array
    {
        return $this->ensureCurrentFormat() ? $this->catalog['extras']['failure_transports'] : [];
    }

    #[Override]
    public function metricBuckets(string $metric): ?array
    {
        return $this->ensureCurrentFormat() ? $this->catalog['extras']['metric_buckets'][$metric] ?? null : null;
    }

    #[Override]
    public function metricLabelOf(PointId $point): ?string
    {
        if (!$this->ensureCurrentFormat()) {
            return null;
        }
        $key = $point->toString();
        foreach ($this->catalog['extras']['tagged_points'] as $tagged) {
            if ($tagged['point'] === $key) {
                return $tagged['metric_label'];
            }
        }

        return null;
    }

    #[Override]
    public function controllerServiceOf(string $serviceId): ?string
    {
        return $this->ensureCurrentFormat() ? $this->catalog['extras']['controller_services'][$serviceId] ?? null : null;
    }

    #[Override]
    public function handledMessageClasses(): array
    {
        return $this->ensureCurrentFormat() ? array_column($this->catalog['extras']['handlers'], 'message_class') : [];
    }

    /**
     * Retries a dump that could not be read and points with an unreadable definition; successfully hydrated data is
     * kept (the dump is fixed per container).
     */
    #[Override]
    public function reset(): void
    {
        $this->unreadable = [];
        $this->unreadableWarned = false;
        $this->byPoint = array_diff_key($this->byPoint, $this->partialPoints);
        $this->partialPoints = [];
    }

    private function contents(): CatalogContents
    {
        return $this->contents ??= $this->read('contents', fn (): CatalogContents => CatalogDump::fromArray($this->catalog));
    }

    /**
     * @template T of object
     *
     * @param 'contents'|'extras' $section
     * @param Closure(): T $hydrate
     *
     * @return T
     */
    private function read(string $section, Closure $hydrate): object
    {
        if (isset($this->unreadable[$section])) {
            throw $this->unreadable[$section];
        }
        try {
            $format = $this->catalog['format'] ?? 0;
            if ($format !== CatalogDump::FORMAT) {
                throw $this->formatMismatch($format);
            }

            return $hydrate();
        } catch (Throwable $e) {
            throw $this->unreadable[$section] = new RuntimeException('The compiled SLO catalog cannot be read; clear the cache (bin/console cache:clear) after upgrading slo-bundle.', 0, $e);
        }
    }

    /**
     * Runtime guard: false (with the one-time warning) when the dump was written by another bundle version.
     */
    private function ensureCurrentFormat(): bool
    {
        $format = $this->catalog['format'] ?? 0;
        if ($format === CatalogDump::FORMAT) {
            return true;
        }
        $this->warnUnreadable($this->formatMismatch($format));

        return false;
    }

    private function formatMismatch(int $format): UnexpectedValueException
    {
        return new UnexpectedValueException(\sprintf('SLO catalog dump format %d, expected %d.', $format, CatalogDump::FORMAT));
    }

    private function warnUnreadable(Throwable $e): void
    {
        if ($this->unreadableWarned) {
            return;
        }
        $this->unreadableWarned = true;
        $this->logger->warning('The compiled SLO catalog cannot be read, its SLOs record nothing; clear the cache.', ['exception' => $e]);
    }
}
