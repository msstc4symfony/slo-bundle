<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Filesystem;

use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexParser;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexStoreInterface;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The route index as a PHP file in the cache directory (opcache-friendly), written by SloCacheWarmer: FrameworkBundle's Router reloads the whole
 * route collection on every getRouteCollection() call, i.e. once per request under PHP-FPM. Ignored in debug mode,
 * where routes may change without a cache clear; outside debug mode a missing or unreadable file is logged once per
 * process, because every request then pays the route load.
 *
 * @internal
 */
final class RouteIndexFile implements RouteIndexStoreInterface
{
    public const PATH = 'msstc4symfony_slo/route_index.php';

    private bool $included = false;

    /** @var array<array-key, mixed>|null the included file when it is an index of the current version */
    private ?array $raw = null;

    private bool $loaded = false;

    private ?RouteIndexData $data = null;

    /** @var array<string, list<PointId>|null> */
    private array $points = [];

    /** @var array<string, Revision|false|null> */
    private array $revisions = [];

    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $debug,
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
    public function write(string $cacheDir, RouteIndexData $data): void
    {
        $file = $cacheDir . '/' . self::PATH;
        AtomicFile::write($file, "<?php\n\nreturn " . var_export($data->toArray(), true) . ";\n");
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

        $file = $this->cacheDir . '/' . self::PATH;
        try {
            $raw = is_file($file) ? $this->include($file) : null;
        } catch (Throwable) {
            $raw = null;
        }

        if (!RouteIndexParser::isCurrentVersion($raw)) {
            $this->logger->warning('SLO route index is not warmed, HTTP SLOs read the routes from the router in this process (degraded mode); run cache:warmup.', ['file' => $file]);

            return null;
        }

        return $this->raw = $raw;
    }

    private function include(string $file): mixed
    {
        return include $file;
    }
}
