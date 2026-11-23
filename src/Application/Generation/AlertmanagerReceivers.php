<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class AlertmanagerReceivers
{
    public const NAME_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}$/D';

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
            if (!self::isValidName($name)) {
                throw new InvalidArgumentException(\sprintf('The %s receiver name "%s" must match %s.', $severity, $name, self::NAME_PATTERN));
            }
        }
    }

    /**
     * @phpstan-assert-if-true non-empty-string $name
     */
    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    public function names(): array
    {
        return $this->page === $this->ticket ? [$this->page] : [$this->page, $this->ticket];
    }
}
