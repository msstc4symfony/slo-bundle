<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog;

use Closure;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\DefinitionFactory;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CatalogDump;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;

final class CatalogFixture
{
    /**
     * @param Closure(CatalogAssembler): void $declare
     */
    public static function build(Closure $declare, ?CatalogExtras $extras = null): CompiledSloCatalog
    {
        $assembler = new CatalogAssembler();
        $declare($assembler);
        $errors = new DiscoveryErrors();
        $contents = $assembler->assemble(new DefinitionFactory('30d', ['5xx'], [], true, false), $errors, $extras ?? new CatalogExtras());

        return new CompiledSloCatalog(CatalogDump::toArray($contents));
    }
}
