<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use LogicException;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexFile;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class RouteIndexFileTest extends TestCase
{
    private const FINGERPRINT = 'catalog-a';

    private string $dir;

    #[Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/msstc4symfony-slo-route-index-' . getmypid() . '-' . bin2hex(random_bytes(6));
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ([$this->dir, \dirname($this->dir . '/' . RouteIndexFile::PATH)] as $dir) {
            if (is_dir($dir)) {
                chmod($dir, 0o755);
            }
        }
        (new Filesystem())->remove($this->dir);
    }

    public function testWrittenIndexIsLoadedBack(): void
    {
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $this->data());
        $loaded = (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load();

        self::assertInstanceOf(RouteIndexData::class, $loaded);
        self::assertEquals($this->data(), $loaded);
        self::assertSame(['pay_create', 'pay_create_v2', 'pay_list'], array_map(static fn (RouteInfo $r): string => $r->name, $loaded->all()));
        self::assertEquals([new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create'), new RouteInfo('pay_create_v2', '/v2/pay', ['POST'], 'App\Pay::create')], $loaded->routesFor('App\Pay::create'));
        self::assertSame([], $loaded->routesFor('Unknown::x'));
        self::assertEquals([PointId::of(PointType::HttpRoute, 'App\Pay::create')], $loaded->pointsFor('pay_create'));
        self::assertSame([], $loaded->pointsFor('pay_list'));
        self::assertStringStartsWith("<?php\n\nreturn ", (string) file_get_contents($this->dir . '/' . RouteIndexFile::PATH));
    }

    public function testNumericRouteNamesSurviveBuildingWritingAndLoading(): void
    {
        $point = PointId::of(PointType::HttpRoute, 'App\Error::show');
        $routes = [new RouteInfo('404', '/404', ['GET'], 'App\Error::show'), new RouteInfo('500', '/500', [], 'App\Error::show'), new RouteInfo('home', '/', [], null)];
        $built = RouteIndexData::fromRoutes($routes, ['404' => [$point]]);

        self::assertSame(['404', '500', 'home'], array_map(static fn (RouteInfo $r): string => $r->name, $built->all()));
        self::assertSame(['404', '500'], array_map(static fn (RouteInfo $r): string => $r->name, $built->routesFor('App\Error::show')));

        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $built);
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT);
        $loaded = $file->load();

        self::assertInstanceOf(RouteIndexData::class, $loaded);
        self::assertSame(['404', '500', 'home'], array_map(static fn (RouteInfo $r): string => $r->name, $loaded->all()));
        self::assertEquals([$point], $loaded->pointsFor('404'));
        self::assertEquals([$point], $file->pointsFor('404'));
        self::assertSame(['404', '500'], array_map(static fn (RouteInfo $r): string => $r->name, $file->routesFor('App\Error::show') ?? []));
    }

    public function testRewritingReplacesTheIndex(): void
    {
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $this->data());
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, RouteIndexData::fromRoutes([], []));

        self::assertSame([], (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load()?->all());
    }

    public function testMissingFileLoadsNothing(): void
    {
        self::assertNull((new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
    }

    public function testDebugModeIgnoresTheFileBecauseRoutesMayChangeWithoutACacheClear(): void
    {
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $this->data());

        self::assertNull((new RouteIndexFile($this->dir, true, self::FINGERPRINT))->load());
    }

    /**
     * File-level cases; the structure of the index is covered by RouteIndexParserTest.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        $valid = ['fingerprint' => self::FINGERPRINT, 'version' => RouteIndexData::VERSION, 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => 'A::b']], 'controllers' => ['A::b' => ['a']], 'points' => ['a' => ['http_route:A::b']], 'revisions' => []];
        $file = static fn (array $index): string => '<?php return ' . var_export($index, true) . ';';

        yield 'not an array' => ['<?php return 42;'];
        yield 'other version' => [$file(['version' => RouteIndexData::VERSION - 1] + $valid)];
        yield 'other fingerprint' => [$file(['fingerprint' => 'catalog-b'] + $valid)];
        yield 'no fingerprint' => [$file(array_diff_key($valid, ['fingerprint' => true]))];
        yield 'route without path' => [$file(['routes' => ['a' => ['methods' => [], 'controller' => 'A::b']]] + $valid)];
        yield 'throws' => ["<?php throw new \\RuntimeException('broken');"];
    }

    public function testTheBaseOfTheMalformedCasesIsValid(): void
    {
        $this->dump(['version' => RouteIndexData::VERSION, 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => 'A::b']], 'controllers' => ['A::b' => ['a']], 'points' => ['a' => ['http_route:A::b']], 'revisions' => []]);

        self::assertNotNull((new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
    }

    public function testAnIndexStoredForAnotherCatalogIsNotTrustedAndIsReplaced(): void
    {
        (new RouteIndexFile($this->dir, false, 'catalog-old'))->write($this->dir, RouteIndexData::fromRoutes([new RouteInfo('old', '/old', [], 'App\\Old::run')], []));
        $logger = new RecordingLogger();
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger);

        self::assertNull($file->pointsFor('old'));
        self::assertNull($file->revisionOf(ObjectiveKey::of(SloName::fromString('pay.create'), ObjectiveKind::Availability)));
        self::assertNull($file->load());
        self::assertCount(1, $logger->records, 'the degraded-mode warning');

        $file->persist(fn (): RouteIndexData => $this->data());

        self::assertEquals($this->data(), (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
        self::assertNull((new RouteIndexFile($this->dir, false, 'catalog-old'))->load());
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedFileLoadsNothing(string $contents): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . RouteIndexFile::PATH, $contents);

        self::assertNull((new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
    }

    public function testHotPathReadsValidateOnlyTheEntryTheyReturn(): void
    {
        $this->dump([
            'version' => RouteIndexData::VERSION,
            'routes' => ['pay' => ['path' => '/pay', 'methods' => ['POST'], 'controller' => 'A::b'], 'broken' => 'x'],
            'controllers' => ['A::b' => ['pay'], 'C::d' => ['broken']],
            'points' => ['pay' => ['http_route:A::b'], 'broken' => ['nope']],
            'revisions' => ['pay.create|availability' => 'abcdef12', 'pay.create|latency' => '', 'pay.broken|latency' => 'ABCDEF12'],
        ]);
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT);

        self::assertEquals([PointId::of(PointType::HttpRoute, 'A::b')], $file->pointsFor('pay'));
        self::assertSame([], $file->pointsFor('other'));
        self::assertNull($file->pointsFor('broken'));
        self::assertEquals(Revision::fromHex('abcdef12'), $file->revisionOf(ObjectiveKey::of(SloName::fromString('pay.create'), ObjectiveKind::Availability)));
        self::assertFalse($file->revisionOf(ObjectiveKey::of(SloName::fromString('pay.create'), ObjectiveKind::Latency)));
        self::assertNull($file->revisionOf(ObjectiveKey::of(SloName::fromString('pay.broken'), ObjectiveKind::Latency)));
        self::assertNull($file->revisionOf(ObjectiveKey::of(SloName::fromString('pay.unknown'), ObjectiveKind::Latency)));
        self::assertEquals([new RouteInfo('pay', '/pay', ['POST'], 'A::b')], $file->routesFor('A::b'));
        self::assertSame([], $file->routesFor('E::f'));
        self::assertNull($file->routesFor('C::d'));
        self::assertNull($file->load());
    }

    #[DataProvider('malformedProvider')]
    public function testHotPathReadsOfAMalformedFileNeverThrow(string $contents): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . RouteIndexFile::PATH, $contents);
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT);

        $file->pointsFor('a');
        $file->routesFor('A::b');

        self::assertNull($file->revisionOf(ObjectiveKey::of(SloName::fromString('pay'), ObjectiveKind::Availability)));
    }

    public function testWithoutAWarmedIndexEveryHotPathReadIsNullAndTheDegradedModeIsLoggedOnce(): void
    {
        $logger = new RecordingLogger();
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger);

        self::assertNull($file->pointsFor('pay'));
        self::assertNull($file->revisionOf(ObjectiveKey::of(SloName::fromString('pay.create'), ObjectiveKind::Availability)));
        self::assertNull($file->routesFor('A::b'));
        self::assertNull($file->load());
        self::assertSame([['warning', 'SLO route index is not warmed, HTTP SLOs read the routes from the router in this process and try to store the index for the next ones (degraded mode); run cache:warmup.']], $logger->records);
        self::assertSame(['file' => $this->dir . '/' . RouteIndexFile::PATH], $logger->contexts[0]);
    }

    public function testAnIndexOfAnotherCatalogIsLoggedAsForeignNotAsNotWarmed(): void
    {
        (new RouteIndexFile($this->dir, false, 'catalog-old'))->write($this->dir, RouteIndexData::fromRoutes([new RouteInfo('old', '/old', [], 'App\\Old::run')], []));
        $logger = new RecordingLogger();

        (new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger))->pointsFor('old');

        self::assertCount(1, $logger->records);
        self::assertStringStartsWith('SLO route index was stored for a different SLO catalog', $logger->records[0][1]);
        self::assertStringNotContainsString('not warmed', $logger->records[0][1]);
    }

    public function testAnIndexBuiltOnTheRequestPathIsStoredOnceSoTheNextProcessIsNotDegraded(): void
    {
        $logger = new RecordingLogger();
        $first = new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger);
        self::assertNull($first->pointsFor('pay_create'));

        $builds = 0;
        $build = function () use (&$builds): RouteIndexData {
            $builds++;

            return $this->data();
        };
        $first->persist($build);
        $first->persist($build);

        $next = new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger);
        self::assertSame(1, $builds);
        self::assertEquals([PointId::of(PointType::HttpRoute, 'App\Pay::create')], $next->pointsFor('pay_create'));
        self::assertEquals($this->data(), $next->load());
        self::assertCount(1, $logger->records, 'only the first process is degraded');
    }

    public function testNothingIsStoredInDebugModeOrOverACurrentIndex(): void
    {
        $build = static fn (): RouteIndexData => throw new LogicException('must not build');

        (new RouteIndexFile($this->dir, true, self::FINGERPRINT))->persist($build);
        self::assertFileDoesNotExist($this->dir . '/' . RouteIndexFile::PATH);

        $warmed = RouteIndexData::fromRoutes([new RouteInfo('warmed', '/w', [], null)], []);
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $warmed);
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->persist($build);
        self::assertEquals($warmed, (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
    }

    public function testPersistReplacesAnIndexOfAnotherVersion(): void
    {
        $this->dump(['version' => RouteIndexData::VERSION - 1] + $this->data()->toArray());
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT);
        self::assertNull($file->pointsFor('pay_create'));

        $file->persist(fn (): RouteIndexData => $this->data());

        self::assertEquals($this->data(), (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
    }

    public function testPersistRepairsAMalformedIndexOfTheCurrentVersionAndSaysSo(): void
    {
        $broken = $this->data()->toArray();
        $broken['points']['pay_create'] = ['nope'];
        $this->dump($broken);
        $logger = new RecordingLogger();
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger);
        self::assertNull($file->pointsFor('pay_create'));

        $file->persist(fn (): RouteIndexData => $this->data());

        self::assertSame(['file' => $this->dir . '/' . RouteIndexFile::PATH], $this->contextOf($logger, 'SLO route index is malformed, it is replaced by the index built from the router; run cache:warmup.'));
        self::assertEquals($this->data(), (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
    }

    public function testConcurrentWritersLeaveOneCompleteIndexAndNoTemporaryFile(): void
    {
        $first = new RouteIndexFile($this->dir, false, self::FINGERPRINT);
        $second = new RouteIndexFile($this->dir, false, self::FINGERPRINT);
        self::assertNull($first->pointsFor('pay_create'));
        self::assertNull($second->pointsFor('pay_create'));

        $first->persist(fn (): RouteIndexData => $this->data());
        $second->persist(fn (): RouteIndexData => $this->data());

        self::assertEquals($this->data(), (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
        self::assertSame([], glob(\dirname($this->dir . '/' . RouteIndexFile::PATH) . '/.*.tmp'));
    }

    public function testAFailedWriteIsLoggedOnceAndNotRetriedInTheProcess(): void
    {
        (new Filesystem())->mkdir($this->dir . '/' . RouteIndexFile::PATH);
        $logger = new RecordingLogger();
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger);
        $builds = 0;
        $build = function () use (&$builds): RouteIndexData {
            $builds++;

            return $this->data();
        };

        $file->persist($build);
        $file->persist($build);

        self::assertSame(1, $builds);
        self::assertInstanceOf(RuntimeException::class, $this->contextOf($logger, 'SLO route index built on the request path could not be stored.')['exception'] ?? null);
        self::assertCount(2, $logger->records, 'the degraded-mode warning and one write failure');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function readOnlyProvider(): iterable
    {
        yield 'read-only cache directory' => [false];
        yield 'read-only index directory' => [true];
    }

    #[DataProvider('readOnlyProvider')]
    public function testAnUnwritableTargetIsDetectedBeforeTheIndexIsBuilt(bool $indexDirectoryExists): void
    {
        $readOnly = $indexDirectoryExists ? \dirname($this->dir . '/' . RouteIndexFile::PATH) : $this->dir;
        (new Filesystem())->mkdir($readOnly);
        chmod($readOnly, 0o555);
        if (is_writable($readOnly)) {
            self::markTestSkipped('The process can write into read-only directories (root).');
        }
        $builds = 0;

        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->persist(function () use (&$builds): RouteIndexData {
            $builds++;

            return $this->data();
        });

        self::assertSame(0, $builds);
        self::assertFileDoesNotExist($this->dir . '/' . RouteIndexFile::PATH);
    }

    public function testACacheDirectoryThatIsAFileIsDetectedBeforeTheIndexIsBuilt(): void
    {
        (new Filesystem())->dumpFile($this->dir, 'a file, not a directory');
        $logger = new RecordingLogger();

        (new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger))->persist(static fn (): RouteIndexData => throw new LogicException('must not build'));

        self::assertSame(['SLO route index is not warmed and the cache directory is not writable, HTTP SLOs read the routes from the router in every process (degraded mode); run cache:warmup.'], array_column($logger->records, 1));
        self::assertSame('a file, not a directory', file_get_contents($this->dir));
    }

    public function testAFailingBuildIsLoggedAndNotStored(): void
    {
        $logger = new RecordingLogger();

        (new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger))->persist(static fn (): RouteIndexData => throw new RuntimeException('catalog broken'));

        self::assertInstanceOf(RuntimeException::class, $this->contextOf($logger, 'SLO route index built on the request path could not be stored.')['exception'] ?? null);
        self::assertFileDoesNotExist($this->dir . '/' . RouteIndexFile::PATH);
    }

    public function testDegradedModeIsNotLoggedInDebugOrWithAWarmedIndex(): void
    {
        $logger = new RecordingLogger();
        (new RouteIndexFile($this->dir, true, self::FINGERPRINT, $logger))->pointsFor('pay');
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $this->data());
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT, $logger))->pointsFor('pay');

        self::assertSame([], $logger->records);
    }

    public function testLoadIsMemoised(): void
    {
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $this->data());
        $file = new RouteIndexFile($this->dir, false, self::FINGERPRINT);
        $first = $file->load();
        (new Filesystem())->remove($this->dir);

        self::assertSame($first, $file->load());
    }

    public function testALinkPlantedAtTheIndexPathIsReplacedNotWrittenThrough(): void
    {
        $victim = $this->dir . '/victim.txt';
        (new Filesystem())->dumpFile($victim, 'untouched');
        (new Filesystem())->mkdir(\dirname($this->dir . '/' . RouteIndexFile::PATH));
        symlink($victim, $this->dir . '/' . RouteIndexFile::PATH);

        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $this->data());

        self::assertSame('untouched', file_get_contents($victim));
        self::assertFalse(is_link($this->dir . '/' . RouteIndexFile::PATH));
        self::assertEquals($this->data(), (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->load());
        self::assertSame([], glob(\dirname($this->dir . '/' . RouteIndexFile::PATH) . '/.*.tmp'));
    }

    public function testUnwritableDirectoryThrows(): void
    {
        (new Filesystem())->dumpFile($this->dir, 'a file, not a directory');

        $this->expectException(RuntimeException::class);
        (new RouteIndexFile($this->dir, false, self::FINGERPRINT))->write($this->dir, $this->data());
    }

    public function testPointsAreComputedFromTheCatalog(): void
    {
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'App\Pay::create'), new Slo('pay.create', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::one'), new Slo('dup.slo', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::two'), new Slo('dup.slo', [new Availability(99.5)]));
        });
        $routes = [...$this->routes(), new RouteInfo('one', '/one', [], 'A::one'), new RouteInfo('none', '/none', [], null)];

        self::assertEquals(
            ['one' => [PointId::of(PointType::HttpRoute, 'A::one')], 'pay_create' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')], 'pay_create_v2' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')]],
            RouteIndexData::pointsOf($routes, $catalog),
        );
    }

    /**
     * Stores a hand-made index as this container's.
     *
     * @param array<array-key, mixed> $index
     */
    private function dump(array $index): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . RouteIndexFile::PATH, '<?php return ' . var_export(['fingerprint' => self::FINGERPRINT] + $index, true) . ';');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function contextOf(RecordingLogger $logger, string $message): array
    {
        $index = array_search($message, array_column($logger->records, 1), true);
        self::assertIsInt($index, $message . ' was not logged');

        return $logger->contexts[$index];
    }

    private function data(): RouteIndexData
    {
        return RouteIndexData::fromRoutes($this->routes(), [
            'pay_create' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')],
            'pay_create_v2' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')],
        ]);
    }

    /**
     * @return list<RouteInfo>
     */
    private function routes(): array
    {
        return [
            new RouteInfo('pay_list', '/pay', ['GET'], 'App\Pay::list'),
            new RouteInfo('pay_create_v2', '/v2/pay', ['POST'], 'App\Pay::create'),
            new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create'),
        ];
    }
}
