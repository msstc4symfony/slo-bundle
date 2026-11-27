<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskOutcomeMiddleware;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskRunMiddleware;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Puts ScheduledTaskOutcomeMiddleware first and ScheduledTaskRunMiddleware right before `handle_message` in the
 * middleware list FrameworkBundle keeps per bus (`<bus>.middleware`) until MessengerPass reads it, so it must run
 * first. A bus without `handle_message` in that list (default middleware disabled) has no scheduled-task runs
 * measured: the container log says so.
 *
 * @internal
 */
final class RegisterScheduledTaskMiddlewarePass implements CompilerPassInterface
{
    /** The ids MessengerPass resolves to the handle-message middleware. */
    private const HANDLE_MESSAGE = ['handle_message', 'messenger.middleware.handle_message'];

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ScheduledTaskRunMiddleware::class) || !$container->hasDefinition(ScheduledTaskOutcomeMiddleware::class)) {
            return;
        }

        foreach (array_keys($container->findTaggedServiceIds('messenger.bus')) as $bus) {
            $parameter = $bus . '.middleware';
            if (!$container->hasParameter($parameter)) {
                continue;
            }
            $middleware = $container->getParameter($parameter);
            if (!\is_array($middleware)) {
                continue;
            }
            $ids = array_map(static fn (mixed $item): mixed => \is_array($item) ? ($item['id'] ?? null) : null, $middleware);
            if (\in_array(ScheduledTaskRunMiddleware::class, $ids, true)) {
                continue;
            }
            $position = null;
            foreach ($ids as $index => $id) {
                if (\in_array($id, self::HANDLE_MESSAGE, true)) {
                    $position = $index;

                    break;
                }
            }
            if (!\is_int($position)) {
                $container->log($this, \sprintf('Bus "%s" has no handle_message middleware: its scheduled-task runs are not measured.', $bus));

                continue;
            }
            array_splice($middleware, $position, 0, [['id' => ScheduledTaskRunMiddleware::class]]);
            array_unshift($middleware, ['id' => ScheduledTaskOutcomeMiddleware::class]);
            $container->setParameter($parameter, $middleware);
        }
    }
}
