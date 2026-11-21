<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Symfony\Contracts\Service\Attribute\Required;

#[Slo(name: 'catalog.read', objectives: [new Availability(99.5)])]
final class CatalogController
{
    private string $source = 'db';

    public function list(): string
    {
        return 'list';
    }

    public function show(): string
    {
        return 'show from ' . $this->source;
    }

    #[Required]
    public function setSource(string $source = 'cache'): self
    {
        $this->source = $source;

        return $this;
    }

    public static function create(): self
    {
        return new self();
    }
}
