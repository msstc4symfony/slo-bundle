<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

/**
 * @internal
 */
final class DiscoveryErrors
{
    /** @var array<non-empty-string, true> */
    private array $messages = [];

    /**
     * @param non-empty-string $message
     */
    public function add(string $message): void
    {
        $this->messages[$message] = true;
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /**
     * @return list<non-empty-string> sorted, without duplicates
     */
    public function messages(): array
    {
        $messages = array_keys($this->messages);
        sort($messages, SORT_STRING);

        return $messages;
    }

    public function summary(): string
    {
        $messages = $this->messages();

        return \sprintf("SLO discovery found %d error%s:\n - %s", \count($messages), \count($messages) === 1 ? '' : 's', implode("\n - ", $messages));
    }
}
