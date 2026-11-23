<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate;

/**
 * @internal
 */
final readonly class Input
{
    /**
     * @param string|null $outputDir overrides GenerationConfig::$outputDir
     * @param bool $check compare with the files on disk without writing
     * @param string|null $pageReceiver overrides generation.alertmanager.receivers.page
     * @param string|null $ticketReceiver overrides generation.alertmanager.receivers.ticket
     */
    public function __construct(
        public ?string $outputDir = null,
        public bool $check = false,
        public ?string $pageReceiver = null,
        public ?string $ticketReceiver = null,
    ) {
    }
}
