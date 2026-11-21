<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Logging;

use Override;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * A failing logger must not break fail-open SLO recording.
 */
final class FailSafeLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }
}
