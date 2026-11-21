<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $records = [];

    /** @var list<array<array-key, mixed>> */
    public array $contexts = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->contexts[] = $context;
        $this->records[] = [\is_string($level) ? $level : '', (string) $message];
    }
}
