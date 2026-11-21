<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexStoreInterface;
use Override;

final class InMemoryRouteIndexStoreContractTest extends RouteIndexStoreContractTestCase
{
    #[Override]
    protected function store(?RouteIndexData $stored): RouteIndexStoreInterface
    {
        return new InMemoryRouteIndexStore($stored);
    }

    #[Override]
    protected function reopen(RouteIndexStoreInterface $store): RouteIndexStoreInterface
    {
        return $store;
    }
}
