<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Infrastructure\Grafana\DeterministicJson;
use PHPUnit\Framework\TestCase;

final class DeterministicJsonTest extends TestCase
{
    public function testObjectKeysAreSortedRecursivelyAndListsKeepTheirOrder(): void
    {
        $json = (new DeterministicJson())->encode([
            'b' => ['y' => 1, 'x' => [3, 1, 2]],
            'a' => [['z' => 1, 'a' => 2], ['k' => 'v']],
            'empty' => [],
        ]);

        self::assertSame(
            <<<'JSON'
                {
                    "a": [
                        {
                            "a": 2,
                            "z": 1
                        },
                        {
                            "k": "v"
                        }
                    ],
                    "b": {
                        "x": [
                            3,
                            1,
                            2
                        ],
                        "y": 1
                    },
                    "empty": []
                }

                JSON,
            $json,
        );
    }

    public function testSlashesAreNotEscapedZeroFractionsAreKeptAndTheOutputEndsWithANewline(): void
    {
        $json = (new DeterministicJson())->encode(['expr' => 'a/b "c" é', 'max' => 1.0]);

        self::assertStringContainsString('"expr": "a/b \"c\" \\u00e9"', $json);
        self::assertStringContainsString('"max": 1.0', $json);
        self::assertStringEndsWith("}\n", $json);
    }
}
