<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Override;

/**
 * Routes come from the warmed route index, else from the router on first use, memoised: routes are fixed per
 * container (ruling 7). routesFor() reads only the controller's entry of the warmed index.
 *
 * @internal
 */
final class RouterRouteCatalog implements RouteCatalogInterface
{
    private ?RouteIndexData $data = null;

    public function __construct(
        private readonly RouterRouteReader $reader,
        private readonly RouteIndexStoreInterface $file,
    ) {
    }

    #[Override]
    public function routesFor(string $controller): array
    {
        if (!$this->data instanceof RouteIndexData) {
            $warmed = $this->file->routesFor($controller);
            if ($warmed !== null) {
                return $warmed;
            }
        }

        return $this->data()->routesFor($controller);
    }

    #[Override]
    public function all(): array
    {
        return $this->data()->all();
    }

    private function data(): RouteIndexData
    {
        return $this->data ??= $this->file->load() ?? RouteIndexData::fromRoutes($this->reader->read(), []);
    }
}
