<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use LogicException;
use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every RouteIndexStoreInterface implementation shares, so the in-memory fake cannot drift from the file.
 */
abstract class RouteIndexStoreContractTestCase extends TestCase
{
    public function testWithoutAnIndexEveryReadIsNull(): void
    {
        $store = $this->store(null);

        self::assertNull($store->pointsFor('pay_create'));
        self::assertNull($store->revisionOf($this->key('pay.create', ObjectiveKind::Availability)));
        self::assertNull($store->routesFor('App\Pay::create'));
        self::assertNull($store->load());
    }

    public function testAStoredIndexAnswersEveryRead(): void
    {
        $store = $this->store($this->data());

        self::assertEquals([PointId::of(PointType::HttpRoute, 'App\Pay::create')], $store->pointsFor('pay_create'));
        self::assertSame([], $store->pointsFor('pay_list'));
        self::assertSame([], $store->pointsFor('unknown'));
        self::assertEquals(Revision::fromHex('abcdef12'), $store->revisionOf($this->key('pay.create', ObjectiveKind::Latency)));
        self::assertFalse($store->revisionOf($this->key('pay.create', ObjectiveKind::Availability)));
        self::assertNull($store->revisionOf($this->key('pay.unknown', ObjectiveKind::Availability)));
        self::assertEquals([new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create'), new RouteInfo('pay_create_v2', '/v2/pay', ['POST'], 'App\Pay::create')], $store->routesFor('App\Pay::create'));
        self::assertSame([], $store->routesFor('Unknown::x'));
        self::assertEquals($this->data(), $store->load());
    }

    public function testAPersistedIndexIsReadNextTime(): void
    {
        $store = $this->store(null);
        $store->pointsFor('pay_create');

        $store->persist(fn (): RouteIndexData => $this->data());

        $next = $this->reopen($store);
        self::assertEquals([PointId::of(PointType::HttpRoute, 'App\Pay::create')], $next->pointsFor('pay_create'));
        self::assertEquals($this->data(), $next->load());
    }

    public function testPersistNeverReplacesAStoredIndex(): void
    {
        $store = $this->store($this->data());

        $store->persist(static fn (): RouteIndexData => throw new LogicException('must not build'));

        self::assertEquals($this->data(), $this->reopen($store)->load());
    }

    /**
     * @param RouteIndexData|null $stored the index the store holds before the test, null for none
     */
    abstract protected function store(?RouteIndexData $stored): RouteIndexStoreInterface;

    /**
     * The store as the next process sees it.
     */
    abstract protected function reopen(RouteIndexStoreInterface $store): RouteIndexStoreInterface;

    protected function data(): RouteIndexData
    {
        return RouteIndexData::fromRoutes(
            [
                new RouteInfo('pay_list', '/pay', ['GET'], 'App\Pay::list'),
                new RouteInfo('pay_create_v2', '/v2/pay', ['POST'], 'App\Pay::create'),
                new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create'),
            ],
            ['pay_create' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')], 'pay_create_v2' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')]],
            ['pay.create|availability' => '', 'pay.create|latency' => 'abcdef12'],
        );
    }

    /**
     * @param non-empty-string $slo
     */
    private function key(string $slo, ObjectiveKind $kind): ObjectiveKey
    {
        return ObjectiveKey::of(SloName::fromString($slo), $kind);
    }
}
