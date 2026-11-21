<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Serialization;

use InvalidArgumentException;

/**
 * Recursive key sorting shared by the deterministic JSON and YAML writers: equal data always serialises to equal bytes.
 *
 * @internal
 */
final class SortedKeys
{
    /**
     * Lists keep their order, maps are sorted by key (as strings). A map keyed exactly 0..n-1 out of order is rejected
     * whatever its size: sorted it would be a list (a JSON array instead of an object) below 11 keys and a map above.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException for a map keyed like a list out of order
     */
    public static function of(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::of($value);
            }
        }
        if (!array_is_list($data)) {
            if (self::hasListKeys($data)) {
                throw new InvalidArgumentException(\sprintf('A map with the keys 0..%d would serialise as a list: deterministic output needs string-keyed maps.', \count($data) - 1));
            }
            ksort($data, SORT_STRING);
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function hasListKeys(array $data): bool
    {
        $count = \count($data);
        foreach (array_keys($data) as $key) {
            if (!\is_int($key) || $key < 0 || $key >= $count) {
                return false;
            }
        }

        return true;
    }
}
