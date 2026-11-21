<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Catalog;

use Closure;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\DefinitionFactory;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Application\Discovery\HandlerInfo;
use Msstc4Symfony\SloBundle\Application\Discovery\TaggedPoint;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CatalogDump;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @phpstan-import-type CatalogShape from CatalogDump
 */
final class CompiledSloCatalogTest extends TestCase
{
    private const CREATE = 'App\Controller::create';

    private const PAY = 'App\Message\Pay';

    public function testIndexesPointsAtDumpTime(): void
    {
        $dump = $this->dump();

        self::assertSame(['http_route:' . self::CREATE => [0], 'messenger_message:' . self::PAY => [1]], $dump['point_index']);
        self::assertSame(['http_route:' . self::CREATE => ['payment.conflict']], $dump['conflicted_points']);
    }

    public function testCorruptDefinitionDoesNotThrowFromTheConstructorAndOnlyItsPointIsEmpty(): void
    {
        $dump = $this->corruptDump();
        $logger = new RecordingLogger();

        $catalog = new CompiledSloCatalog($dump, $logger);

        for ($i = 0; $i < 2; $i++) {
            self::assertSame([], $catalog->forPoint($this->create()));
        }
        self::assertSame('payment.process', $catalog->forPoint($this->pay())[0]->name()->value);
        self::assertSame(['payment.conflict'], $catalog->conflictedNamesFor($this->create()));
        self::assertTrue($catalog->hasHttpPoints());
        self::assertSame(['failed'], $catalog->failureTransports());
        self::assertSame([['warning', 'The compiled SLO catalog cannot be read, its SLOs record nothing; clear the cache.']], $logger->records);
    }

    public function testStaleDumpWithoutIndexesRecordsNothingAndWarnsOnce(): void
    {
        $dump = $this->dump();
        $stale = $dump;
        unset($stale['format'], $stale['point_index'], $stale['conflicted_points'], $stale['extras']['has_http_points']);
        $logger = new RecordingLogger();

        // Reason: a dump written by an older bundle version does not match the current CatalogShape on purpose.
        // @phpstan-ignore argument.type
        $catalog = new CompiledSloCatalog($stale, $logger);

        self::assertSame([], $catalog->forPoint($this->create()));
        self::assertSame([], $catalog->conflictedNamesFor($this->create()));
        self::assertFalse($catalog->hasHttpPoints());
        self::assertSame([], $catalog->failureTransports());
        self::assertCount(1, $logger->records);
    }

    public function testDiagnosticsOfAnUnreadableDumpFailLoudly(): void
    {
        $catalog = new CompiledSloCatalog($this->corruptDump(), new RecordingLogger());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The compiled SLO catalog cannot be read; clear the cache');

        $catalog->all();
    }

    public function testFailedFullHydrationIsMemoisedUntilResetAndDoesNotHideTheExtras(): void
    {
        $catalog = new CompiledSloCatalog($this->corruptDump(), new RecordingLogger());

        $first = $this->failure(static fn (): array => $catalog->all());
        self::assertSame($first, $this->failure(static fn (): array => $catalog->conflicts()));
        self::assertSame(['failed'], $catalog->extras()->failureTransports);
        self::assertSame('payment.process', $catalog->forPoint($this->pay())[0]->name()->value);

        $catalog->reset();

        $retried = $this->failure(static fn (): array => $catalog->all());
        self::assertNotSame($first, $retried);
        self::assertSame($first->getMessage(), $retried->getMessage());
    }

    public function testCheapRuntimeReadsUseTheRawDump(): void
    {
        $dump = $this->dump(new CatalogExtras(
            handlers: [
                new HandlerInfo(self::class, self::PAY, true, null, '__invoke'),
                new HandlerInfo(self::class, 'Legacy\\Pay', true, null, '__invoke'),
                new HandlerInfo(self::class, 'App\\Message\\Refund', true, null, '__invoke'),
            ],
            taggedPoints: [
                new TaggedPoint(PointId::of(PointType::LogicAction, 'refund'), 'refund_label'),
                new TaggedPoint(PointId::of(PointType::LogicAction, 'other'), null),
            ],
            metricBuckets: ['logic_action_duration_histogram_seconds' => [0.1, 1.0]],
        ));
        $catalog = new CompiledSloCatalog($dump, new RecordingLogger());

        self::assertSame([0.1, 1.0], $catalog->metricBuckets('logic_action_duration_histogram_seconds'));
        self::assertNull($catalog->metricBuckets('request_duration_histogram_seconds'));
        self::assertSame('refund_label', $catalog->metricLabelOf(PointId::of(PointType::LogicAction, 'refund')));
        self::assertNull($catalog->metricLabelOf(PointId::of(PointType::LogicAction, 'other')));
        self::assertNull($catalog->metricLabelOf(PointId::of(PointType::LogicAction, 'unknown')));
        self::assertSame([self::PAY, 'Legacy\\Pay'], $catalog->handledMessageClassesLabelled('Pay'));
        self::assertSame(['App\\Message\\Refund'], $catalog->handledMessageClassesLabelled('Refund'));
        self::assertSame([], $catalog->handledMessageClassesLabelled('Unknown'));

        $stale = $dump;
        $stale['format'] = 2;
        $staleCatalog = new CompiledSloCatalog($stale, new RecordingLogger());
        self::assertNull($staleCatalog->metricBuckets('logic_action_duration_histogram_seconds'));
        self::assertNull($staleCatalog->metricLabelOf(PointId::of(PointType::LogicAction, 'refund')));
        self::assertSame([], $staleCatalog->handledMessageClassesLabelled('Pay'));
    }

    public function testUndeclaredPointsReadAsEmptyWithoutAWarning(): void
    {
        $logger = new RecordingLogger();
        $catalog = new CompiledSloCatalog($this->dump(), $logger);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame([], $catalog->forPoint(PointId::of(PointType::Operation, 'dynamic.' . $i)));
        }

        self::assertSame($catalog->forPoint($this->pay()), $catalog->forPoint($this->pay()));
        self::assertSame([], $logger->records);
    }

    public function testFailedGetKeepsThrowingUntilReset(): void
    {
        $catalog = new CompiledSloCatalog($this->corruptDump(), new RecordingLogger());
        $name = SloName::fromString('payment.process');

        $first = $this->failure(static fn (): ?SloDefinition => $catalog->get($name));
        self::assertSame($first, $this->failure(static fn (): ?SloDefinition => $catalog->get($name)));
        $catalog->reset();
        self::assertSame($first->getMessage(), $this->failure(static fn (): ?SloDefinition => $catalog->get($name))->getMessage());
    }

    public function testCorruptDefinitionSkipsOnlyItselfAndItsPointIsRetriedAfterReset(): void
    {
        $assembler = new CatalogAssembler();
        $assembler->declare($this->create(), new Slo('payment.create', [new Availability(99.9)]));
        $assembler->declare($this->create(), new Slo('payment.health', [new Availability(99)]));

        $errors = new DiscoveryErrors();
        $dump = CatalogDump::toArray($assembler->assemble(new DefinitionFactory('30d', ['5xx'], [], true, false), $errors, new CatalogExtras()), new MetricsBundleSeriesCatalog());
        $dump['definitions'] = array_map(static fn (array $d): array => $d['name'] === 'payment.create' ? ['window' => '5m'] + $d : $d, $dump['definitions']);
        $logger = new RecordingLogger();
        $catalog = new CompiledSloCatalog($dump, $logger);

        for ($i = 0; $i < 2; $i++) {
            self::assertSame(['payment.health'], $this->names($catalog->forPoint($this->create())));
        }
        self::assertCount(1, $logger->records);

        $catalog->reset();

        self::assertSame(['payment.health'], $this->names($catalog->forPoint($this->create())));
        self::assertCount(2, $logger->records);
    }

    public function testHydratesTheSameDefinitionOnceForEveryPoint(): void
    {
        $catalog = new CompiledSloCatalog($this->dump(), new RecordingLogger());

        self::assertSame($catalog->forPoint($this->create()), $catalog->forPoint($this->create()));
        self::assertSame($catalog->forPoint($this->pay())[0], $catalog->all()[1]);
        self::assertSame(['payment.create', 'payment.process'], array_map(static fn (SloDefinition $d): string => $d->name()->value, $catalog->all()));
        self::assertSame(['failed'], $catalog->extras()->failureTransports);
    }

    /**
     * @param Closure(): mixed $call
     */
    private function failure(Closure $call): RuntimeException
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            return $e;
        }

        self::fail('Expected a RuntimeException.');
    }

    /**
     * @param list<SloDefinition> $definitions
     *
     * @return list<string>
     */
    private function names(array $definitions): array
    {
        return array_map(static fn (SloDefinition $d): string => $d->name()->value, $definitions);
    }

    private function create(): PointId
    {
        return PointId::of(PointType::HttpRoute, self::CREATE);
    }

    private function pay(): PointId
    {
        return PointId::of(PointType::MessengerMessage, self::PAY);
    }

    /**
     * @return CatalogShape payment.create carries a window the current code rejects
     */
    private function corruptDump(): array
    {
        $dump = $this->dump();
        $dump['definitions'] = array_map(static fn (array $d): array => $d['name'] === 'payment.create' ? ['window' => '5m'] + $d : $d, $dump['definitions']);

        return $dump;
    }

    /**
     * @return CatalogShape
     */
    private function dump(?CatalogExtras $extras = null): array
    {
        $assembler = new CatalogAssembler();
        $assembler->declare($this->create(), new Slo('payment.create', [new Availability(99.9)]));
        $assembler->declare($this->create(), new Slo('payment.conflict', [new Availability(99.9)]));
        $assembler->declare($this->create(), new Slo('payment.conflict', [new Availability(99.5)]));
        $assembler->declare($this->pay(), new Slo('payment.process', [new Availability(99)]));

        $errors = new DiscoveryErrors();
        $extras ??= new CatalogExtras(failureTransports: ['failed'], hasHttpPoints: true);
        $contents = $assembler->assemble(new DefinitionFactory('30d', ['5xx'], [], true, false), $errors, $extras);
        self::assertTrue($errors->isEmpty(), $errors->summary());

        return CatalogDump::toArray($contents, new MetricsBundleSeriesCatalog());
    }
}
