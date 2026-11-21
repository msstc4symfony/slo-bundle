<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

final readonly class HandlerInfo
{
    /**
     * @param class-string $handlerClass
     * @param non-empty-string $messageClass
     * @param non-empty-string|null $fromTransport
     * @param non-empty-string $method
     */
    public function __construct(
        public string $handlerClass,
        public string $messageClass,
        public bool $async,
        public ?string $fromTransport,
        public string $method = '__invoke',
    ) {
    }
}
