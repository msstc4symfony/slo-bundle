<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use InvalidArgumentException;

/**
 * Series filter of every generated query, taken from explicit configuration (never from the runtime env, spec 5.6).
 */
final readonly class Selector
{
    /**
     * @var list<non-empty-string> sorted, unique
     */
    public array $components;

    /**
     * @param non-empty-string $application value of the `application` label
     * @param list<string> $components values of the `component` label, empty = no filter
     */
    public function __construct(
        public string $application,
        array $components,
    ) {
        $valid = [];
        foreach ($components as $component) {
            if ($component === '') {
                throw new InvalidArgumentException('generation.selector.components must not contain an empty component name.');
            }
            $valid[] = $component;
        }
        $valid = array_values(array_unique($valid));
        sort($valid, SORT_STRING);

        $this->components = $valid;
    }
}
