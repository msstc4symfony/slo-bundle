<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use RuntimeException;
use Throwable;

/**
 * The route index that SloCacheWarmer writes into the cache directory: FrameworkBundle's Router reloads the whole
 * route collection on every getRouteCollection() call, i.e. once per request under PHP-FPM. Ignored in debug mode,
 * where routes may change without a cache clear.
 *
 * @internal
 */
final class RouteIndexFile
{
    public const PATH = 'msstc4symfony_slo/route_index.php';

    private bool $included = false;

    private mixed $raw = null;

    private bool $loaded = false;

    private ?RouteIndexData $data = null;

    private bool $pointsLoaded = false;

    /** @var array<array-key, non-empty-list<PointId>>|null */
    private ?array $points = null;

    private bool $revisionsLoaded = false;

    /** @var array<non-empty-string, string>|null */
    private ?array $revisions = null;

    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $debug,
    ) {
    }

    /**
     * The whole index, validated. Never throws: a missing, unreadable or malformed file loads nothing and the callers
     * fall back to the router.
     */
    public function load(): ?RouteIndexData
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->data = RouteIndexData::fromArray($this->raw());
        }

        return $this->data;
    }

    /**
     * Route name => SLO points, validating only that section (hot path). Never throws.
     *
     * @return array<array-key, non-empty-list<PointId>>|null
     */
    public function points(): ?array
    {
        if (!$this->pointsLoaded) {
            $this->pointsLoaded = true;
            $this->points = RouteIndexData::pointsFromArray($this->raw());
        }

        return $this->points;
    }

    /**
     * "<slo>|<objective>" => revision of a dedicated objective or '' (hot path). Never throws.
     *
     * @return array<non-empty-string, string>|null
     */
    public function revisions(): ?array
    {
        if (!$this->revisionsLoaded) {
            $this->revisionsLoaded = true;
            $this->revisions = RouteIndexData::revisionsFromArray($this->raw());
        }

        return $this->revisions;
    }

    /**
     * @throws RuntimeException when the file cannot be written
     */
    public static function write(string $cacheDir, RouteIndexData $data): void
    {
        $file = $cacheDir . '/' . self::PATH;
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException(\sprintf('Directory "%s" cannot be created.', $dir));
        }

        $temporary = $file . '.' . bin2hex(random_bytes(6));
        $contents = "<?php\n\nreturn " . var_export($data->toArray(), true) . ";\n";
        if (@file_put_contents($temporary, $contents) === false || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException(\sprintf('File "%s" cannot be written.', $file));
        }
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    private function raw(): mixed
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
            $this->raw = is_file($file) ? $this->include($file) : null;
        } catch (Throwable) {
            $this->raw = null;
        }

        return $this->raw;
    }

    private function include(string $file): mixed
    {
        return include $file;
    }
}
