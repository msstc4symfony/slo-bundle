<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\GenerationConfig;

/**
 * @internal
 */
final readonly class AlertmanagerReceivers
{
    /**
     * @param non-empty-string $page
     * @param non-empty-string $ticket
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public string $page,
        public string $ticket,
    ) {
        foreach (['page' => $page, 'ticket' => $ticket] as $severity => $name) {
            if (preg_match(GenerationConfig::LABEL_VALUE, $name) !== 1) {
                throw new InvalidArgumentException(\sprintf('The %s receiver name "%s" must match %s.', $severity, $name, GenerationConfig::LABEL_VALUE));
            }
        }
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    public function names(): array
    {
        return $this->page === $this->ticket ? [$this->page] : [$this->page, $this->ticket];
    }
}
