<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

#[Slo(name: 'catalog.read', objectives: [new Availability(99.5)])]
final class CatalogController
{
    public function list(): string
    {
        return 'list';
    }

    public function show(): string
    {
        return 'show';
    }

    public static function create(): self
    {
        return new self();
    }
}
