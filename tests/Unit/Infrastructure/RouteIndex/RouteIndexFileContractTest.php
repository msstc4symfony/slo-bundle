<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexFile;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexStoreInterface;
use Override;
use Symfony\Component\Filesystem\Filesystem;

final class RouteIndexFileContractTest extends RouteIndexStoreContractTestCase
{
    private string $dir;

    #[Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/msstc4symfony-slo-route-index-contract-' . getmypid();
        (new Filesystem())->remove($this->dir);
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    #[Override]
    protected function store(?RouteIndexData $stored): RouteIndexStoreInterface
    {
        if ($stored instanceof RouteIndexData) {
            (new RouteIndexFile($this->dir, false))->write($this->dir, $stored);
        }

        return new RouteIndexFile($this->dir, false);
    }

    #[Override]
    protected function reopen(RouteIndexStoreInterface $store): RouteIndexStoreInterface
    {
        return new RouteIndexFile($this->dir, false);
    }
}
