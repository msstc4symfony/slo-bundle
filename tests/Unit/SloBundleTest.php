<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit;

use Msstc4Symfony\SloBundle\SloBundle;
use PHPUnit\Framework\TestCase;

final class SloBundleTest extends TestCase
{
    public function testExtensionAlias(): void
    {
        self::assertSame('msstc4symfony_slo', (new SloBundle())->getContainerExtension()?->getAlias());
    }
}
