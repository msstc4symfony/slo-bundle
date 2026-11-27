<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Override;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Stands for a transaction commit failing after a successful handler.
 */
final class FailAfterHandlingMiddleware implements MiddlewareInterface
{
    public static bool $failing = false;

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $handled = $stack->next()->handle($envelope, $stack);
        if (self::$failing) {
            throw new RuntimeException('Commit failed.');
        }

        return $handled;
    }
}
