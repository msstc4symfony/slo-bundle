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
     * Lists keep their order, maps are sorted by key (as strings). Maps are meant to be string-keyed: one keyed exactly
     * 0..n-1 out of order would become a list (a JSON array instead of an object) and is rejected.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException for a map that sorting would turn into a list
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
            if (array_is_list($data)) {
                throw new InvalidArgumentException(\sprintf('A map with the keys 0..%d would serialise as a list: deterministic output needs string-keyed maps.', \count($data) - 1));
            }
        }

        return $data;
    }
}
