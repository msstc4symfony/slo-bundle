<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

/**
 * @internal
 */
final readonly class RouteInfo
{
    /**
     * @param non-empty-string $name
     * @param list<string> $methods empty = any method
     * @param non-empty-string|null $controller "Class::method" (invokable: "Class::__invoke"), null when not resolvable
     */
    public function __construct(
        public string $name,
        public string $path,
        public array $methods,
        public ?string $controller,
    ) {
    }
}
