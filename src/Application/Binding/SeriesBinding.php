<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use InvalidArgumentException;

/**
 * Where an objective reads its good/total events on series metrics-bundle already exports.
 *
 * badMatchers and goodMatchers override same-label entries of matchers; both are empty for histogram latency, where
 * good is the `le` bucket and total is `_count`. For availability, total = matchers, bad = matchers + badMatchers,
 * good = matchers + goodMatchers.
 *
 * @internal
 */
final readonly class SeriesBinding
{
    /**
     * @param non-empty-string $metric unprefixed metric name, e.g. "http_response"
     * @param array<non-empty-string, Matcher> $matchers label => matcher, sorted by label
     * @param array<non-empty-string, Matcher> $goodMatchers label => matcher, sorted by label
     * @param array<non-empty-string, Matcher> $badMatchers label => matcher, sorted by label
     * @param non-empty-string|null $le histogram bucket bound as exported, null when no bucket is addressed
     */
    public function __construct(
        public string $metric,
        public SeriesKind $kind,
        public array $matchers,
        public array $goodMatchers,
        public array $badMatchers,
        public ?string $le,
    ) {
        foreach ([$matchers, $goodMatchers, $badMatchers] as $set) {
            foreach ($set as $label => $matcher) {
                if ($matcher->label !== $label) {
                    throw new InvalidArgumentException(\sprintf('Matcher for label "%s" is keyed as "%s".', $matcher->label, $label));
                }
            }
        }
    }

    /**
     * @param list<Matcher> $matchers
     *
     * @return array<non-empty-string, Matcher>
     */
    public static function index(array $matchers): array
    {
        $indexed = [];
        foreach ($matchers as $matcher) {
            $indexed[$matcher->label] = $matcher;
        }
        ksort($indexed, SORT_STRING);

        return $indexed;
    }
}
