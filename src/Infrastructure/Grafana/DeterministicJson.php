<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

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
        return json_encode(self::sorted($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function sorted(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::sorted($value);
            }
        }
        if (!array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
