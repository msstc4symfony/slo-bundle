<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Infrastructure\Serialization\SortedKeys;

/**
 * JSON with recursively sorted object keys, so equal data always encodes to equal bytes.
 *
 * @internal
 */
final readonly class DeterministicJson
{
    /**
     * @param array<array-key, mixed> $data lists keep their order, objects are sorted by key
     */
    public function encode(array $data): string
    {
        return json_encode(SortedKeys::of($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }
}
