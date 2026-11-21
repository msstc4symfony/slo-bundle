<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\RouteIndex;

use Closure;
use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\Filesystem\AtomicFile;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The route index as a PHP file in the cache directory (opcache-friendly), written by SloCacheWarmer or, outside debug
 * mode, by the request path (persist()): FrameworkBundle's Router reloads the whole route collection on every
 * getRouteCollection() call, i.e. once per request under PHP-FPM. Ignored in debug mode, where routes may change
 * without a cache clear. Outside debug mode a missing or unreadable file is logged once per process and the index the
 * request path builds is stored when the cache directory is writable, so the rebuild happens once per deploy.
 *
 * @internal
 */
final class RouteIndexFile implements RouteIndexStoreInterface
{
    public const PATH = 'msstc4symfony_slo/route_index.php';

    private const FINGERPRINT = 'fingerprint';

    private bool $included = false;

    /** @var array<array-key, mixed>|null the included file when it is an index of the current version */
    private ?array $raw = null;

    private bool $loaded = false;

    private ?RouteIndexData $data = null;

    private bool $persisted = false;

    private ?bool $writable = null;

    /** @var array<string, list<PointId>|null> */
    private array $points = [];

    /** @var array<string, Revision|false|null> */
    private array $revisions = [];

    /**
     * @param non-empty-string $fingerprint identifies the catalog of this container: a file stored by another one (an old
     *                                      process persisting into a cache directory cleared in place) is not trusted
     */
    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $debug,
        private readonly string $fingerprint,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function load(): ?RouteIndexData
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->data = RouteIndexParser::parse($this->raw());
        }

        return $this->data;
    }

    #[Override]
    public function pointsFor(string $route): ?array
    {
        if (\array_key_exists($route, $this->points)) {
            return $this->points[$route];
        }
        $raw = $this->raw();

        $points = $raw === null ? null : RouteIndexParser::pointsEntry($raw, $route);
        $this->points[$route] = $points;

        return $points;
    }

    #[Override]
    public function revisionOf(ObjectiveKey $key): Revision|false|null
    {
        if (\array_key_exists($key->value, $this->revisions)) {
            return $this->revisions[$key->value];
        }
        $raw = $this->raw();

        return $this->revisions[$key->value] = $raw === null ? null : RouteIndexParser::revisionEntry($raw, $key->value);
    }

    #[Override]
    public function routesFor(string $controller): ?array
    {
        $raw = $this->raw();

        return $raw === null ? null : RouteIndexParser::routesEntry($raw, $controller);
    }

    #[Override]
    public function persist(Closure $build): void
    {
        if ($this->debug || $this->persisted || $this->load() instanceof RouteIndexData) {
            return;
        }
        $this->persisted = true;
        if (!$this->isWritable()) {
            return;
        }
        if ($this->raw() !== null) {
            $this->logger->warning('SLO route index is malformed, it is replaced by the index built from the router; run cache:warmup.', ['file' => $this->file()]);
        }
        try {
            $this->write($this->cacheDir, $build());
        } catch (Throwable $e) {
            $this->logger->warning('SLO route index built on the request path could not be stored.', ['exception' => $e]);
        }
    }

    #[Override]
    public function write(string $cacheDir, RouteIndexData $data): void
    {
        $file = $cacheDir . '/' . self::PATH;
        AtomicFile::write($file, "<?php\n\nreturn " . var_export([self::FINGERPRINT => $this->fingerprint] + $data->toArray(), true) . ";\n");
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function raw(): ?array
    {
        if ($this->included) {
            return $this->raw;
        }
        $this->included = true;
        if ($this->debug) {
            return null;
        }

        $file = $this->file();
        try {
            $raw = is_file($file) ? $this->include($file) : null;
        } catch (Throwable) {
            $raw = null;
        }

        if (!RouteIndexParser::isCurrentVersion($raw) || ($raw[self::FINGERPRINT] ?? null) !== $this->fingerprint) {
            $this->logger->warning($this->isWritable()
                ? 'SLO route index is not warmed, HTTP SLOs read the routes from the router in this process and try to store the index for the next ones (degraded mode); run cache:warmup.'
                : 'SLO route index is not warmed and the cache directory is not writable, HTTP SLOs read the routes from the router in every process (degraded mode); run cache:warmup.', ['file' => $file]);

            return null;
        }

        return $this->raw = $raw;
    }

    private function file(): string
    {
        return $this->cacheDir . '/' . self::PATH;
    }

    /**
     * Checked before the index is built, so a read-only cache directory costs a few stat calls, not a whole build.
     */
    private function isWritable(): bool
    {
        if ($this->writable !== null) {
            return $this->writable;
        }
        $directory = \dirname($this->file());
        while (!file_exists($directory)) {
            $parent = \dirname($directory);
            if ($parent === $directory) {
                return $this->writable = false;
            }
            $directory = $parent;
        }

        return $this->writable = is_dir($directory) && is_writable($directory);
    }

    private function include(string $file): mixed
    {
        return include $file;
    }
}
