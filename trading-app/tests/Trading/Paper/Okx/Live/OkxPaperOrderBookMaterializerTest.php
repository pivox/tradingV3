<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\Live\OkxPaperBookDeltaResult;
use App\Trading\Paper\Okx\Live\OkxPaperBookDeltaStatus;
use App\Trading\Paper\Okx\Live\OkxPaperOrderBookMaterializer;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperBookDeltaStatus::class)]
#[CoversClass(OkxPaperBookDeltaResult::class)]
#[CoversClass(OkxPaperOrderBookMaterializer::class)]
#[CoversClass(OkxMaterializedBookState::class)]
final class OkxPaperOrderBookMaterializerTest extends TestCase
{
    public function testPublicApiUsesExplicitNonNullableDeltaResults(): void
    {
        $materializer = new \ReflectionClass(OkxPaperOrderBookMaterializer::class);
        $result = new \ReflectionClass(OkxPaperBookDeltaResult::class);

        self::assertSame(
            ['applyDelta', 'replaceSnapshot', 'sourceSequence'],
            $this->sortedPublicInstanceMethodNames($materializer),
        );
        self::assertSame(OkxMaterializedBookState::class, (string) $materializer->getMethod('replaceSnapshot')->getReturnType());
        self::assertSame(OkxPaperBookDeltaResult::class, (string) $materializer->getMethod('applyDelta')->getReturnType());
        self::assertSame('?string', (string) $materializer->getMethod('sourceSequence')->getReturnType());
        self::assertSame(OkxPaperBookDeltaStatus::class, (string) $result->getMethod('status')->getReturnType());
        self::assertSame(OkxMaterializedBookState::class, (string) $result->getMethod('materializedState')->getReturnType());
        self::assertFalse($result->getMethod('materializedState')->getReturnType()?->allowsNull());
        self::assertTrue($result->isReadOnly());
        self::assertSame('applied', OkxPaperBookDeltaStatus::APPLIED->value);
        self::assertSame('replayed', OkxPaperBookDeltaStatus::REPLAYED->value);
    }

    public function testRestAndWebSocketSnapshotsReplaceTheCompleteStateAndExposeSequence(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        self::assertNull($materializer->sourceSequence());

        $rest = $materializer->replaceSnapshot($this->fixture('order-book.json')['data'][0]);

        self::assertSame('123457', $materializer->sourceSequence());
        self::assertSame('123457', $rest->sourceSequence);
        self::assertNull($rest->sourcePreviousSequence);
        self::assertSame('65070.4000', $rest->bestBid()['price']);
        self::assertSame('65070.5000', $rest->bestAsk()['price']);

        $webSocketFixture = $this->fixture('ws-books-snapshot.json');
        $webSocket = $materializer->replaceSnapshot($webSocketFixture['data'][0]);

        self::assertSame('223457', $materializer->sourceSequence());
        self::assertSame('223457', $webSocket->sourceSequence);
        self::assertSame('-1', $webSocket->sourcePreviousSequence);
        self::assertSame('65080.4000', $webSocket->bestBid()['price']);
        self::assertSame('65080.5000', $webSocket->bestAsk()['price']);
    }

    public function testDeltaBeforeSnapshotFailsClosed(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();

        try {
            $materializer->applyDelta($this->fixture('ws-books-update.json')['data'][0]);
            self::fail('A delta before a complete snapshot must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertNull($materializer->sourceSequence());
    }

    public function testExistingWebSocketUpdateUpsertsExactPricesIntoTheCompleteSortedBook(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        $materializer->replaceSnapshot($this->ethSnapshot());

        $result = $materializer->applyDelta($this->fixture('ws-books-update.json')['data'][0]);

        self::assertSame(OkxPaperBookDeltaStatus::APPLIED, $result->status());
        $state = $result->materializedState();
        self::assertSame('323457', $materializer->sourceSequence());
        self::assertSame('323457', $state->sourceSequence);
        self::assertSame('323456', $state->sourcePreviousSequence);
        self::assertSame(
            ['price' => '3525.4000', 'size' => '5.250', 'order_count' => '3'],
            $state->bestBid(),
        );
        self::assertSame(
            ['price' => '3525.6000', 'size' => '3.500', 'order_count' => '2'],
            $state->bestAsk(),
        );
    }

    public function testZeroSizeDeletesOnlyTheExactDecimalPriceAndKeepsBothSidesSorted(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        $materializer->replaceSnapshot($this->ethSnapshot());
        $materializer->applyDelta($this->fixture('ws-books-update.json')['data'][0]);

        $firstDeletion = $materializer->applyDelta([
            'asks' => [['3525.6000', '0', '0', '0']],
            'bids' => [['3525.4000', '0', '0', '0']],
            'ts' => '1784595706123',
            'prevSeqId' => 323457,
            'seqId' => 323458,
        ])->materializedState();

        self::assertSame('3525.3000', $firstDeletion->bestBid()['price']);
        self::assertSame('3525.7000', $firstDeletion->bestAsk()['price']);

        $exactPrice = $materializer->applyDelta([
            'asks' => [],
            'bids' => [
                ['3525.3', '0', '0', '0'],
                ['3525.3500', '1.000', '98765432109876543210', '2'],
            ],
            'ts' => '1784595707123',
            'prevSeqId' => 323458,
            'seqId' => 323459,
        ])->materializedState();

        self::assertSame('3525.3500', $exactPrice->bestBid()['price']);
        self::assertSame('98765432109876543210', $this->lastAppliedRawThirdField($materializer, '3525.3500'));

        $deleteInserted = $materializer->applyDelta([
            'asks' => [],
            'bids' => [['3525.3500', '0', '0', '0']],
            'ts' => '1784595708123',
            'prevSeqId' => 323459,
            'seqId' => 323460,
        ])->materializedState();

        self::assertSame('3525.3000', $deleteInserted->bestBid()['price']);
    }

    public function testVeryLargeSequenceValuesUseArbitraryPrecisionComparison(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        $current = '92233720368547758081234567890';
        $next = (string) BigInteger::of($current)->plus(1);
        $snapshot = $this->ethSnapshot($current);

        $materializer->replaceSnapshot($snapshot);
        $result = $materializer->applyDelta([
            'asks' => [],
            'bids' => [],
            'ts' => '1784595705123',
            'prevSeqId' => $current,
            'seqId' => $next,
        ]);

        self::assertSame(OkxPaperBookDeltaStatus::APPLIED, $result->status());
        self::assertSame($next, $materializer->sourceSequence());
        self::assertSame($next, $result->materializedState()->sourceSequence);
    }

    /** @param callable(array<string, mixed>): void $mutate */
    #[DataProvider('invalidSequenceProvider')]
    public function testNegativeOrNonIncreasingSequenceFailsWithoutPublishingCandidate(
        callable $mutate,
    ): void {
        $materializer = new OkxPaperOrderBookMaterializer();
        $before = $materializer->replaceSnapshot($this->ethSnapshot());
        $delta = [
            'asks' => [['3525.6000', '1.000', '0', '1']],
            'bids' => [],
            'ts' => '1784595705123',
            'prevSeqId' => 323456,
            'seqId' => 323457,
        ];
        $mutate($delta);

        try {
            $materializer->applyDelta($delta);
            self::fail('Invalid sequence data must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertSame('323456', $materializer->sourceSequence());
        self::assertSame('3525.3000', $before->bestBid()['price']);
        self::assertSame('3525.7000', $before->bestAsk()['price']);
        $after = $this->applyNoop($materializer, '323456', '323457')->materializedState();
        self::assertSame('3525.3000', $after->bestBid()['price']);
        self::assertSame('3525.7000', $after->bestAsk()['price']);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): void}> */
    public static function invalidSequenceProvider(): iterable
    {
        yield 'negative previous sequence' => [static function (array &$delta): void { $delta['prevSeqId'] = '-1'; }];
        yield 'negative sequence' => [static function (array &$delta): void { $delta['seqId'] = '-1'; }];
        yield 'equal sequence' => [static function (array &$delta): void { $delta['seqId'] = 323456; }];
        yield 'regressed sequence' => [static function (array &$delta): void { $delta['seqId'] = 323455; }];
    }

    public function testNegativeSnapshotSequenceFailsWithoutReplacingPriorState(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        $before = $materializer->replaceSnapshot($this->ethSnapshot());
        $invalid = $this->ethSnapshot('-1');
        $invalid['bids'][0][1] = '999.000';

        try {
            $materializer->replaceSnapshot($invalid);
            self::fail('A negative snapshot sequence must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertSame('323456', $materializer->sourceSequence());
        self::assertSame('3525.3000', $before->bestBid()['price']);
        self::assertSame('3525.3000', $this->applyNoop($materializer, '323456', '323457')->materializedState()->bestBid()['price']);
    }

    public function testGapFixtureRaisesExactCodeAndLeavesPriorMaterializedStateUntouched(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        $materializer->replaceSnapshot($this->ethSnapshot());
        $applied = $materializer->applyDelta($this->fixture('ws-books-update.json')['data'][0])->materializedState();
        $gap = $this->fixture('ws-books-gap.json');

        try {
            $materializer->applyDelta($gap['data'][0]);
            self::fail('A strict prevSeqId gap must interrupt materialization.');
        } catch (\RuntimeException $exception) {
            self::assertSame('okx_paper_book_sequence_gap', $exception->getMessage());
        }

        self::assertSame('323457', $materializer->sourceSequence());
        self::assertSame('3525.4000', $applied->bestBid()['price']);
        self::assertSame('3525.6000', $applied->bestAsk()['price']);
        $after = $this->applyNoop($materializer, '323457', '323458')->materializedState();
        self::assertSame('3525.4000', $after->bestBid()['price']);
        self::assertSame('3525.6000', $after->bestAsk()['price']);
    }

    public function testExactCanonicalReplayReturnsExplicitStatusWithoutAState(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        $materializer->replaceSnapshot($this->ethSnapshot());
        $delta = $this->fixture('ws-books-update.json')['data'][0];
        $materializer->applyDelta($delta);

        $reordered = [
            'seqId' => $delta['seqId'],
            'prevSeqId' => $delta['prevSeqId'],
            'checksum' => $delta['checksum'],
            'ts' => $delta['ts'],
            'bids' => $delta['bids'],
            'asks' => $delta['asks'],
        ];
        $replay = $materializer->applyDelta($reordered);

        self::assertSame(OkxPaperBookDeltaStatus::REPLAYED, $replay->status());
        self::assertSame('323457', $materializer->sourceSequence());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('okx_paper_book_delta_state_unavailable');
        $replay->materializedState();
    }

    public function testSameSequencePairWithDifferentCanonicalRowHashConflictsAtomically(): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        $materializer->replaceSnapshot($this->ethSnapshot());
        $delta = $this->fixture('ws-books-update.json')['data'][0];
        $applied = $materializer->applyDelta($delta)->materializedState();
        $conflict = $delta;
        $conflict['bids'][0][1] = '999.000';

        try {
            $materializer->applyDelta($conflict);
            self::fail('A changed canonical row hash for the same sequence pair must conflict.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        self::assertSame('323457', $materializer->sourceSequence());
        self::assertSame('5.250', $applied->bestBid()['size']);
        $after = $materializer->applyDelta([
            'asks' => [],
            'bids' => [['3525.4000', '0', '0', '0']],
            'ts' => '1784595709123',
            'prevSeqId' => 323457,
            'seqId' => 323458,
        ])->materializedState();
        self::assertSame('3525.3000', $after->bestBid()['price']);
        self::assertSame('8.000', $after->bestBid()['size']);
    }

    /** @param callable(array<string, mixed>): void $mutate */
    #[DataProvider('invalidCandidateProvider')]
    public function testInvalidCandidateStateDoesNotMutateThePublishedBook(callable $mutate): void
    {
        $materializer = new OkxPaperOrderBookMaterializer();
        $before = $materializer->replaceSnapshot($this->ethSnapshot());
        $delta = [
            'asks' => [],
            'bids' => [],
            'ts' => '1784595705123',
            'prevSeqId' => 323456,
            'seqId' => 323457,
        ];
        $mutate($delta);

        try {
            $materializer->applyDelta($delta);
            self::fail('An invalid complete candidate book must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('okx_paper_materialized_order_book_invalid', $exception->getMessage());
        }

        self::assertSame('323456', $materializer->sourceSequence());
        self::assertSame('3525.3000', $before->bestBid()['price']);
        self::assertSame('3525.7000', $before->bestAsk()['price']);
        $after = $this->applyNoop($materializer, '323456', '323457')->materializedState();
        self::assertSame('3525.3000', $after->bestBid()['price']);
        self::assertSame('3525.7000', $after->bestAsk()['price']);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): void}> */
    public static function invalidCandidateProvider(): iterable
    {
        yield 'delete every bid' => [static function (array &$delta): void {
            $delta['bids'] = [
                ['3525.3000', '0', '0', '0'],
                ['3525.2000', '0', '0', '0'],
            ];
        }];
        yield 'delete every ask' => [static function (array &$delta): void {
            $delta['asks'] = [
                ['3525.7000', '0', '0', '0'],
                ['3525.8000', '0', '0', '0'],
            ];
        }];
        yield 'cross the book' => [static function (array &$delta): void {
            $delta['bids'] = [['3526.0000', '1.000', '0', '1']];
        }];
        yield 'malformed short row' => [static function (array &$delta): void {
            $delta['bids'] = [['3525.4000', '1.000', '0']];
        }];
        yield 'malformed raw third string' => [static function (array &$delta): void {
            $delta['bids'] = [['3525.4000', '1.000', 'raw', '1']];
        }];
        yield 'non-string size is never converted to zero' => [static function (array &$delta): void {
            $delta['bids'] = [['3525.4000', 0, '0', '0']];
        }];
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private function sortedPublicInstanceMethodNames(\ReflectionClass $class): array
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $class->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => !$method->isStatic()
                    && $method->getDeclaringClass()->getName() === $class->getName(),
            ),
        );
        sort($methods);

        return $methods;
    }

    /** @return array<string, mixed> */
    private function ethSnapshot(int|string $sequence = 323456): array
    {
        return [
            'asks' => [
                ['3525.8000', '4.000', '0', '1'],
                ['3525.7000', '9.000', '0', '5'],
            ],
            'bids' => [
                ['3525.2000', '2.000', '0', '1'],
                ['3525.3000', '3.000', '0', '2'],
            ],
            'ts' => '1784595704123',
            'seqId' => $sequence,
        ];
    }

    private function applyNoop(
        OkxPaperOrderBookMaterializer $materializer,
        string $previous,
        string $sequence,
    ): OkxPaperBookDeltaResult {
        return $materializer->applyDelta([
            'asks' => [],
            'bids' => [],
            'ts' => '1784595709123',
            'prevSeqId' => $previous,
            'seqId' => $sequence,
        ]);
    }

    private function lastAppliedRawThirdField(
        OkxPaperOrderBookMaterializer $materializer,
        string $price,
    ): string {
        $property = new \ReflectionProperty($materializer, 'bids');
        $bids = $property->getValue($materializer);
        self::assertIsArray($bids);
        self::assertArrayHasKey($price, $bids);
        self::assertIsArray($bids[$price]);
        self::assertArrayHasKey('raw_field_3', $bids[$price]);
        self::assertIsString($bids[$price]['raw_field_3']);

        return $bids[$price]['raw_field_3'];
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/OkxPaperPublic/' . $name;
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
