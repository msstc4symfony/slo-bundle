<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Catalog;

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
final class CompiledSloCatalog implements SloCatalogInterface, CatalogDiagnosticsInterface
{
    /** @var array<non-negative-int, SloDefinition> */
    private array $hydrated = [];

    /** @var array<string, list<SloDefinition>> */
    private array $byPoint = [];

    private ?CatalogContents $contents = null;

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
            $this->byName = [];
            foreach ($this->all() as $definition) {
                $this->byName[$definition->name()->value] = $definition;
            }
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

        $definitions = [];
        try {
            if (!$this->isCurrentFormat()) {
                return $this->byPoint[$key] = [];
            }
            foreach ($this->catalog['point_index'][$key] ?? [] as $index) {
                $definitions[] = $this->hydrated[$index] ??= CatalogDump::definition($this->catalog['definitions'][$index]);
            }
        } catch (Throwable $e) {
            $this->warnUnreadable($e);
            $definitions = [];
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
        return $this->isCurrentFormat() ? $this->catalog['conflicted_points'][$point->toString()] ?? [] : [];
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

    #[Override]
    public function extras(): CatalogExtras
    {
        return $this->contents()->extras;
    }

    #[Override]
    public function hasHttpPoints(): bool
    {
        return $this->isCurrentFormat() && $this->catalog['extras']['has_http_points'];
    }

    #[Override]
    public function failureTransports(): array
    {
        return $this->isCurrentFormat() ? $this->catalog['extras']['failure_transports'] : [];
    }

    private function contents(): CatalogContents
    {
        try {
            return $this->contents ??= CatalogDump::fromArray($this->catalog);
        } catch (Throwable $e) {
            throw new RuntimeException('The compiled SLO catalog cannot be read; clear the cache (bin/console cache:clear) after upgrading slo-bundle.', 0, $e);
        }
    }

    private function isCurrentFormat(): bool
    {
        $format = $this->catalog['format'] ?? 0;
        if ($format === CatalogDump::FORMAT) {
            return true;
        }
        $this->warnUnreadable(new UnexpectedValueException(\sprintf('SLO catalog dump format %d, expected %d.', $format, CatalogDump::FORMAT)));

        return false;
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
