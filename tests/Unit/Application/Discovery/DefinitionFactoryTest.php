<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Discovery;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\ObjectiveAttributeInterface;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\DefinitionFactory;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use PHPUnit\Framework\TestCase;

final class DefinitionFactoryTest extends TestCase
{
    private DiscoveryErrors $errors;

    #[Override]
    protected function setUp(): void
    {
        $this->errors = new DiscoveryErrors();
    }

    public function testHttpDefinitionTakesConfiguredDefaults(): void
    {
        $slo = $this->create(new Slo('payment.create', [new Availability(99.95), new Latency('200ms', 99.0, includeErrors: true), new Combined('1.5s', 95)]), PointType::HttpRoute);

        self::assertSame('payment.create', $slo->name()->value);
        self::assertSame(['http_route:App::create'], array_map(static fn (PointId $p): string => $p->toString(), $slo->points()));
        self::assertSame('30d', $slo->window()->toString());
        self::assertSame(StrategyPreference::Auto, $slo->strategyPreference());
        self::assertSame(['5xx'], $slo->classification()->badStatuses->canonical());
        self::assertSame(['404'], $slo->classification()->ineligibleStatuses->canonical());
        self::assertTrue($slo->objective(ObjectiveKind::Latency)?->includeErrors);
        self::assertSame(1500, $slo->objective(ObjectiveKind::Combined)?->threshold?->milliseconds);
    }

    public function testHttpOverridesWin(): void
    {
        $slo = $this->create(new Slo('payment.create', [new Availability(99)], window: '2w', badStatuses: ['5xx', 429], ineligibleStatuses: [], strategy: StrategyPreference::Dedicated), PointType::HttpRoute);

        self::assertSame('2w', $slo->window()->toString());
        self::assertSame(['429', '5xx'], $slo->classification()->badStatuses->canonical());
        self::assertSame([], $slo->classification()->ineligibleStatuses->canonical());
        self::assertSame(StrategyPreference::Dedicated, $slo->strategyPreference());
    }

    public function testNonHttpPointsUseNeutralStatusesAndTheirOwnDefaults(): void
    {
        $neutral = Classification::defaults();

        $messenger = $this->create(new Slo('payment.process', [new Availability(99)]), PointType::MessengerMessage)->classification();
        self::assertSame($neutral->badStatuses->canonical(), $messenger->badStatuses->canonical());
        self::assertSame($neutral->ineligibleStatuses->canonical(), $messenger->ineligibleStatuses->canonical());
        self::assertTrue($messenger->countAttempts);
        self::assertTrue($messenger->expectedFailureIsGood);

        $logic = $this->create(new Slo('refund.action', [new Availability(99)]), PointType::LogicAction)->classification();
        self::assertFalse($logic->expectedFailureIsGood);
        self::assertFalse($logic->countAttempts);

        $operation = $this->create(new Slo('payment.validation', [new Availability(99)], expectedFailureIsGood: true, strategy: StrategyPreference::Dedicated), PointType::Operation);
        self::assertTrue($operation->classification()->expectedFailureIsGood);

        $messengerOverride = $this->create(new Slo('payment.process', [new Availability(99)], countAttempts: false), PointType::MessengerMessage);
        self::assertFalse($messengerOverride->classification()->countAttempts);
    }

    public function testEveryProblemOfOneDeclarationIsReported(): void
    {
        $slo = new Slo(
            'Bad Name',
            [new Availability(100), new Latency('1m', 99, includeErrors: true), new Combined('10us', 99)],
            window: '5m',
            badStatuses: ['5xx'],
            ineligibleStatuses: [600],
            expectedFailureIsGood: true,
            countAttempts: true,
        );

        self::assertNull((new DefinitionFactory('30d', ['5xx'], [], true, false))->fromAttribute($slo, PointId::of(PointType::LogicAction, 'refund'), $this->errors));
        self::assertSame([
            'logic_action:refund: Invalid SLO name "Bad Name": expected /^[a-z][a-z0-9_.-]{0,62}$/D.',
            'logic_action:refund: Invalid SLO target "100.0": expected 0 < target < 100.',
            'logic_action:refund: Invalid SLO window "5m": expected <N>h|d|w|mo, e.g. 30d.',
            'logic_action:refund: Invalid latency threshold "10us": expected <int>ms or <decimal>s.',
            'logic_action:refund: SLO "Bad Name": badStatuses applies to http_route points only.',
            'logic_action:refund: SLO "Bad Name": countAttempts applies to messenger_message points only.',
            'logic_action:refund: SLO "Bad Name": includeErrors applies to http_route points only.',
            'logic_action:refund: SLO "Bad Name": ineligibleStatuses applies to http_route points only.',
        ], $this->errors->messages());
    }

    public function testDuplicateObjectiveKindIsReported(): void
    {
        $slo = new Slo('payment.create', [new Availability(99), new Availability(98)]);

        self::assertNull($this->factory()->fromAttribute($slo, PointId::of(PointType::HttpRoute, 'App::create'), $this->errors));
        self::assertSame(['http_route:App::create: SLO "payment.create" declares the "availability" objective twice.'], $this->errors->messages());
    }

    public function testMissingAndUnsupportedObjectivesAreReported(): void
    {
        $custom = new class implements ObjectiveAttributeInterface {
            #[Override]
            public function kind(): ObjectiveKind
            {
                return ObjectiveKind::Availability;
            }
        };

        self::assertNull($this->factory()->fromAttribute(new Slo('payment.create', [$custom]), PointId::of(PointType::HttpRoute, 'App::create'), $this->errors));
        $messages = $this->errors->messages();
        self::assertCount(1, $messages);
        self::assertStringStartsWith('http_route:App::create: SLO "payment.create": unsupported objective ', $messages[0]);

        $errors = new DiscoveryErrors();
        // Reason: an empty objective list is the value under test; the attribute types it as non-empty-list.
        self::assertNull($this->factory()->fromAttribute(new Slo('payment.create', []), PointId::of(PointType::HttpRoute, 'App::create'), $errors)); // @phpstan-ignore argument.type
        self::assertSame(['http_route:App::create: SLO "payment.create": at least one objective is required.'], $errors->messages());
    }

    public function testDefaultWindowErrorNamesThePoint(): void
    {
        $factory = new DefinitionFactory('1y', ['5xx'], [], true, false);

        self::assertNull($factory->fromAttribute(new Slo('payment.create', [new Availability(99)]), PointId::of(PointType::Operation, 'payment.create'), $this->errors));
        self::assertSame(['operation:payment.create: Invalid SLO window "1y": expected <N>h|d|w|mo, e.g. 30d.'], $this->errors->messages());
    }

    public function testSummaryListsSortedUniqueMessages(): void
    {
        $this->errors->add('b');
        $this->errors->add('a');
        $this->errors->add('b');

        self::assertFalse($this->errors->isEmpty());
        self::assertSame("SLO discovery found 2 errors:\n - a\n - b", $this->errors->summary());

        $single = new DiscoveryErrors();
        self::assertTrue($single->isEmpty());
        $single->add('only');
        self::assertSame("SLO discovery found 1 error:\n - only", $single->summary());
    }

    private function create(Slo $slo, PointType $type): SloDefinition
    {
        $id = match ($type) {
            PointType::HttpRoute => 'App::create',
            PointType::MessengerMessage => 'App\Message\ProcessPayment',
            PointType::LogicAction => 'refund',
            PointType::Operation => 'payment.validation',
        };
        $definition = $this->factory()->fromAttribute($slo, PointId::of($type, $id), $this->errors);

        self::assertSame([], $this->errors->messages());
        self::assertInstanceOf(SloDefinition::class, $definition);

        return $definition;
    }

    private function factory(): DefinitionFactory
    {
        return new DefinitionFactory('30d', ['5xx'], [404], false, true);
    }
}
