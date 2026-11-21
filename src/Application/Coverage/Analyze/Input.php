<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

final readonly class Input
{
    /**
     * @param list<string> $ignoredRoutes routes excluded from coverage (coverage.http_routes.ignored_routes)
     */
    public function __construct(public array $ignoredRoutes = [])
    {
    }
}
