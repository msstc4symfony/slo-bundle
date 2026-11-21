<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\DependencyInjection\CompilerPass;

use LogicException;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\CollectSloDefinitionsPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectSloDefinitionsPassEscapeTest extends TestCase
{
    public function testDoublesPercentInStringValuesAtAnyDepth(): void
    {
        self::assertSame(
            ['reason' => '100%% legacy', 'nested' => ['a%%b', 3, null, true], 'count' => 1],
            CollectSloDefinitionsPass::escape(['reason' => '100% legacy', 'nested' => ['a%b', 3, null, true], 'count' => 1]),
        );
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function providePercentKeys(): iterable
    {
        yield 'nested' => [['extras' => ['controller_services' => ['ok%key' => 'App\Controller']]]];
        yield 'top level' => [['ok%key' => 'value']];
    }

    /**
     * @param array<array-key, mixed> $dump
     */
    #[DataProvider('providePercentKeys')]
    public function testRejectsAPercentInAKeyAtAnyDepth(array $dump): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"ok%key"');

        CollectSloDefinitionsPass::escape($dump);
    }
}
