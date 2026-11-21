<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Discovery;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CatalogAssemblerTest extends TestCase
{
    /**
     * @param non-empty-string $first
     * @param non-empty-string $second
     * @param non-empty-string $merged
     */
    #[TestWith(['30d', '1mo', '1mo'])]
    #[TestWith(['1mo', '30d', '1mo'])]
    #[TestWith(['7d', '1w', '1w'])]
    #[TestWith(['30d', '30d', '30d'])]
    #[TestWith(['48h', '48h', '48h'])]
    public function testMergedWindowIsCanonicalWhenTheDeclarationsSpellItDifferently(string $first, string $second, string $merged): void
    {
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler) use ($first, $second): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::a'), new Slo('x.y', [new Availability(99.9)], window: $first));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::b'), new Slo('x.y', [new Availability(99.9)], window: $second));
        });

        self::assertCount(1, $catalog->all());
        self::assertSame($merged, $catalog->all()[0]->window()->toString());
    }
}
