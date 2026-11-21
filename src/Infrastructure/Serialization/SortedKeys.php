<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Serialization;

/**
 * Recursive key sorting shared by the deterministic JSON and YAML writers: equal data always serialises to equal bytes.
 *
 * @internal
 */
final class SortedKeys
{
    /**
     * Lists keep their order, maps are sorted by key (as strings).
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function of(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::of($value);
            }
        }
        if (!array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
