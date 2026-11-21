<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Logging;

use Psr\Log\LoggerInterface;

/**
 * Logs a warning once per key and remembers at most $capacity keys; beyond that one suppression notice is logged
 * instead. Keys are forgotten only by reset(), so an instance that is never reset logs each key once per process.
 *
 * @internal
 */
final class WarnOnceLimiter
{
    /** @var array<string, true> */
    private array $warned = [];

    private bool $suppressionWarned = false;

    /**
     * @param non-empty-literal-string $suppressionMessage
     * @param positive-int $capacity
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $suppressionMessage,
        private readonly int $capacity = 100,
    ) {
    }

    /**
     * @param non-empty-literal-string $message static text (enforced by type), the variable parts go into $context
     * @param array<string, mixed> $context
     */
    public function warn(string $key, string $message, array $context = []): void
    {
        if (isset($this->warned[$key])) {
            return;
        }
        if (\count($this->warned) >= $this->capacity) {
            if (!$this->suppressionWarned) {
                $this->suppressionWarned = true;
                $this->logger->warning($this->suppressionMessage);
            }

            return;
        }
        $this->warned[$key] = true;
        $this->logger->warning($message, $context);
    }

    public function reset(): void
    {
        $this->warned = [];
        $this->suppressionWarned = false;
    }
}
