<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;

/**
 * A classification option of an SLO declaration.
 *
 * @phpstan-type ClassificationField 'badStatuses'|'ineligibleStatuses'|'expectedFailureIsGood'|'countAttempts'
 */
final readonly class Classification
{
    /**
     * The point types each option applies to; elsewhere it keeps its default so it never changes a revision.
     */
    private const APPLIES_TO = [
        'badStatuses' => [PointType::HttpRoute],
        'ineligibleStatuses' => [PointType::HttpRoute],
        'expectedFailureIsGood' => [PointType::LogicAction, PointType::Operation, PointType::ScheduledTask],
        'countAttempts' => [PointType::MessengerMessage],
    ];

    public function __construct(
        public StatusSet $badStatuses,
        public StatusSet $ineligibleStatuses,
        public bool $expectedFailureIsGood,
        public bool $countAttempts,
    ) {
        if ($badStatuses->intersects($ineligibleStatuses)) {
            throw new InvalidArgumentException(\sprintf('Bad statuses "%s" overlap ineligible statuses "%s".', implode(',', $badStatuses->canonical()), implode(',', $ineligibleStatuses->canonical())));
        }
    }

    public function equals(self $other): bool
    {
        return $this->badStatuses->canonical() === $other->badStatuses->canonical()
            && $this->ineligibleStatuses->canonical() === $other->ineligibleStatuses->canonical()
            && $this->expectedFailureIsGood === $other->expectedFailureIsGood
            && $this->countAttempts === $other->countAttempts;
    }

    public static function defaults(): self
    {
        return new self(StatusSet::fromList(['5xx']), StatusSet::fromList([]), true, false);
    }

    /**
     * @param ClassificationField $field
     *
     * @internal discovery helper, not covered by semantic versioning
     */
    public static function appliesTo(string $field, PointType $type): bool
    {
        return \in_array($type, self::APPLIES_TO[$field], true);
    }

    /**
     * @param ClassificationField $field
     *
     * @return non-empty-list<PointType>
     *
     * @internal discovery helper, not covered by semantic versioning
     */
    public static function pointTypesOf(string $field): array
    {
        return self::APPLIES_TO[$field];
    }

    /**
     * @return list<ClassificationField> options that do not apply to the point type but differ from their default
     *
     * @internal discovery helper, not covered by semantic versioning
     */
    public function inapplicableFieldsFor(PointType $type): array
    {
        $neutral = self::defaults();
        $differs = [
            'badStatuses' => $this->badStatuses->canonical() !== $neutral->badStatuses->canonical(),
            'ineligibleStatuses' => $this->ineligibleStatuses->canonical() !== $neutral->ineligibleStatuses->canonical(),
            'expectedFailureIsGood' => $this->expectedFailureIsGood !== $neutral->expectedFailureIsGood,
            'countAttempts' => $this->countAttempts !== $neutral->countAttempts,
        ];

        return array_keys(array_filter($differs, static fn (bool $differ, string $field): bool => $differ && !self::appliesTo($field, $type), ARRAY_FILTER_USE_BOTH));
    }
}
