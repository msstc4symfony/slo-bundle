<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Yaml;

use Symfony\Component\Yaml\Yaml;

/**
 * YAML with recursively sorted map keys, so equal data always dumps to equal bytes.
 */
final readonly class DeterministicYaml
{
    private const INLINE_LEVEL = 10;

    private const INDENT = 2;

    /**
     * @param array<array-key, mixed> $data lists keep their order, maps are sorted by key
     */
    public function dump(array $data): string
    {
        return Yaml::dump(
            self::sorted($data),
            self::INLINE_LEVEL,
            self::INDENT,
            Yaml::DUMP_COMPACT_NESTED_MAPPING | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE,
        );
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
