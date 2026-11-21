<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Yaml;

use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use PHPUnit\Framework\TestCase;

final class DeterministicYamlTest extends TestCase
{
    public function testMapsAreSortedRecursivelyAndListsKeepTheirOrder(): void
    {
        $yaml = (new DeterministicYaml())->dump([
            'groups' => [
                ['rules' => [['record' => 'b', 'expr' => 'sum(x{a="b"}[5m]) > 0'], ['record' => 'a', 'expr' => '1']], 'name' => 'g', 'interval' => '1m'],
            ],
            'empty' => [],
        ]);

        self::assertSame(
            <<<'YAML'
                empty: []
                groups:
                  - interval: 1m
                    name: g
                    rules:
                      - expr: 'sum(x{a="b"}[5m]) > 0'
                        record: b
                      - expr: '1'
                        record: a

                YAML,
            $yaml,
        );
    }

    public function testDeeplyNestedStructuresStayInBlockStyle(): void
    {
        $yaml = (new DeterministicYaml())->dump(['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => 'i']]]]]]]]);

        self::assertStringEndsWith("              h: i\n", $yaml);
        self::assertStringNotContainsString('{', $yaml);
    }
}
