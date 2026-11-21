<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Catalog;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogDump;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\DefinitionFactory;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
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

        $catalog->forPoint($this->create());
        self::assertSame([], $catalog->forPoint($this->create()));
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

    public function testHydratesTheSameDefinitionOnceForEveryPoint(): void
    {
        $catalog = new CompiledSloCatalog($this->dump(), new RecordingLogger());

        self::assertSame($catalog->forPoint($this->create()), $catalog->forPoint($this->create()));
        self::assertSame($catalog->forPoint($this->pay())[0], $catalog->all()[1]);
        self::assertSame(['payment.create', 'payment.process'], array_map(static fn (SloDefinition $d): string => $d->name()->value, $catalog->all()));
        self::assertSame(['failed'], $catalog->extras()->failureTransports);
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
    private function dump(): array
    {
        $assembler = new CatalogAssembler();
        $assembler->declare($this->create(), new Slo('payment.create', [new Availability(99.9)]));
        $assembler->declare($this->create(), new Slo('payment.conflict', [new Availability(99.9)]));
        $assembler->declare($this->create(), new Slo('payment.conflict', [new Availability(99.5)]));
        $assembler->declare($this->pay(), new Slo('payment.process', [new Availability(99)]));

        $errors = new DiscoveryErrors();
        $extras = new CatalogExtras(failureTransports: ['failed'], hasHttpPoints: true);
        $contents = $assembler->assemble(new DefinitionFactory('30d', ['5xx'], [], true, false), $errors, $extras);
        self::assertTrue($errors->isEmpty(), $errors->summary());

        return CatalogDump::toArray($contents);
    }
}
