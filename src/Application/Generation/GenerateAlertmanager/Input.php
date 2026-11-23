<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateAlertmanager;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * @internal
 */
final readonly class Input
{
    /**
     * @param AlertmanagerReceivers|null $receivers required when enabled
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public Selector $selector,
        public bool $enabled,
        public ?AlertmanagerReceivers $receivers,
    ) {
        if ($enabled && !$receivers instanceof AlertmanagerReceivers) {
            throw new InvalidArgumentException('Alertmanager generation needs the receivers when it is enabled.');
        }
    }
}
