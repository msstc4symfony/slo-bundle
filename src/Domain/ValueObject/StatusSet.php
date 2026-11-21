<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;

final readonly class StatusSet
{
    private const MIN_CODE = 100;

    private const MAX_CODE = 599;

    /**
     * @param list<string> $canonical sorted, deduplicated items: "429", "5xx", "500-503"
     */
    private function __construct(private array $canonical)
    {
    }

    /**
     * @param list<int|string> $items
     */
    public static function fromList(array $items): self
    {
        $canonical = [];
        foreach ($items as $item) {
            $canonical[self::normalise($item)] = true;
        }

        $keys = array_map(strval(...), array_keys($canonical));
        sort($keys, SORT_STRING);

        return new self($keys);
    }

    public function contains(int $status): bool
    {
        foreach ($this->canonical as $item) {
            [$from, $to] = $this->bounds($item);
            if ($status >= $from && $status <= $to) {
                return true;
            }
        }

        return false;
    }

    public function intersects(self $other): bool
    {
        foreach ($this->canonical as $item) {
            [$from, $to] = $this->bounds($item);
            for ($code = $from; $code <= $to; $code++) {
                if ($other->contains($code)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->canonical === [];
    }

    /**
     * @return list<string>
     */
    public function canonical(): array
    {
        return $this->canonical;
    }

    public function toRegex(): string
    {
        $parts = [];
        foreach ($this->canonical as $item) {
            if (str_ends_with($item, 'xx')) {
                $parts[] = $item[0] . '..';
                continue;
            }
            [$from, $to] = $this->bounds($item);
            for ($code = $from; $code <= $to; $code++) {
                $parts[] = (string) $code;
            }
        }

        return implode('|', $parts);
    }

    private static function normalise(int|string $item): string
    {
        if (\is_int($item) || preg_match('/^[0-9]{3}$/D', $item) === 1) {
            $code = (int) $item;
            if ($code < self::MIN_CODE || $code > self::MAX_CODE) {
                throw new InvalidArgumentException(\sprintf('Invalid status code "%s": expected %d-%d.', $item, self::MIN_CODE, self::MAX_CODE));
            }

            return (string) $code;
        }

        if (preg_match('/^([1-5])xx$/Di', $item, $m) === 1) {
            return $m[1] . 'xx';
        }

        if (preg_match('/^([0-9]{3})-([0-9]{3})$/D', $item, $m) === 1) {
            $from = (int) $m[1];
            $to = (int) $m[2];
            if ($from < self::MIN_CODE || $to > self::MAX_CODE || $from > $to) {
                throw new InvalidArgumentException(\sprintf('Invalid status range "%s": expected an ascending range within %d-%d.', $item, self::MIN_CODE, self::MAX_CODE));
            }

            return $from === $to ? (string) $from : $from . '-' . $to;
        }

        throw new InvalidArgumentException(\sprintf('Invalid status item "%s": expected 503, 5xx or 500-504.', $item));
    }

    /**
     * @return array{int, int}
     */
    private function bounds(string $item): array
    {
        if (str_ends_with($item, 'xx')) {
            $base = (int) $item[0] * 100;

            return [$base, $base + 99];
        }

        if (str_contains($item, '-')) {
            [$from, $to] = explode('-', $item);

            return [(int) $from, (int) $to];
        }

        return [(int) $item, (int) $item];
    }
}
