<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Runtime;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ChargeCardHandler
{
    public const SLO = 'card.charge';

    /** @var array<string, int> */
    private array $attempts = [];

    #[Slo(name: self::SLO, objectives: [new Availability(99.9)], strategy: 'dedicated')]
    public function __invoke(ChargeCard $message): string
    {
        $attempt = $this->attempts[$message->id] = ($this->attempts[$message->id] ?? 0) + 1;
        if ($attempt <= $message->failures) {
            throw new RuntimeException(\sprintf('Attempt %d of %s failed.', $attempt, $message->id));
        }

        return $message->id;
    }
}
