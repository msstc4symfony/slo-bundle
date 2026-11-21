<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerateDashboards;

use OutOfBoundsException;
use RuntimeException;

/**
 * Typed read access to decoded JSON for tests: dot-separated paths, a failure instead of a silent null.
 */
final readonly class JsonView
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public static function decode(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new RuntimeException('JSON document is not an object or a list.');
        }

        return new self($data);
    }

    public function has(string $path): bool
    {
        try {
            $this->get($path);

            return true;
        } catch (OutOfBoundsException) {
            return false;
        }
    }

    public function get(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                throw new OutOfBoundsException(\sprintf('"%s" is not in the document.', $path));
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<self> the objects of the list at $path
     */
    public function views(string $path): array
    {
        $list = $this->get($path);
        if (!\is_array($list)) {
            throw new RuntimeException(\sprintf('"%s" is not a list.', $path));
        }
        $views = [];
        foreach ($list as $item) {
            if (!\is_array($item)) {
                throw new RuntimeException(\sprintf('"%s" has a non-object item.', $path));
            }
            $views[] = new self($item);
        }

        return $views;
    }

    /**
     * @return list<mixed> $path of every object in the list at $listPath
     */
    public function column(string $listPath, string $path): array
    {
        return array_map(static fn (self $view): mixed => $view->get($path), $this->views($listPath));
    }
}
