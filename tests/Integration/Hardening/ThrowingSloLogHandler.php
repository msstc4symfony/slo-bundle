<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Hardening;

use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;
use Override;
use RuntimeException;

/**
 * Fails on every record of the bundle's channel, as a broken log shipper would; other channels pass through.
 */
final class ThrowingSloLogHandler extends AbstractHandler
{
    public int $failures = 0;

    #[Override]
    public function handle(LogRecord $record): bool
    {
        if ($record->channel !== 'slo_bundle') {
            return false;
        }
        $this->failures++;

        throw new RuntimeException('log handler broken');
    }
}
