<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\Live\OkxPaperBookDeltaStatus;
use App\Trading\Paper\Okx\Live\OkxPaperOrderBookMaterializer;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The incremental materializer against the full re-materialization it replaces: after
 * every delta, the complete book sorted and validated from scratch (completeState() then
 * fromAppliedDelta()) must give exactly the same state, and a delta rejected by one must
 * be rejected by the other, leaving the book unchanged.
 */
#[CoversClass(OkxPaperOrderBookMaterializer::class)]
#[CoversClass(OkxMaterializedBookState::class)]
final class OkxPaperIncrementalBookEqualityTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function seedProvider(): iterable
    {
        yield 'seed 1' => [1];
        yield 'seed 2' => [2];
        yield 'seed 3' => [3];
    }

    #[DataProvider('seedProvider')]
    public function testAdversarialDeltasMatchTheFullRematerialization(int $seed): void
    {
        mt_srand($seed);
        $replay = new BookReplay();
        $replay->snapshot(self::snapshot(9_000, 400));
        $applied = 0;
        $rejected = 0;
        for ($index = 0; $index < 3_000; ++$index) {
            $replay->delta(self::adversarialDelta($replay), $applied, $rejected);
            if ($index === 1_500) {
                // A recovery snapshot resets the book, its sequence and its best levels.
                $replay->snapshot(self::snapshot($replay->sequence + 7, 200));
            }
        }
        // Every accepted and every rejected delta was compared (~1,900 and ~1,100).
        self::assertGreaterThan(1_500, $applied);
        self::assertGreaterThan(500, $rejected);
    }


    public function testDeltaIdentityIsHashedOnlyWhenOkxRepeatsASequence(): void
    {
        mt_srand(7);
        $materializer = new OkxPaperOrderBookMaterializer();
        $materializer->replaceSnapshot(self::snapshot(10, 5));
        $delta = [
            'asks' => [],
            'bids' => [['2999.9', '3', '0', '1']],
            'ts' => '1784970300011',
            'checksum' => 0,
            'prevSeqId' => '10',
            'seqId' => '11',
        ];
        self::assertSame(OkxPaperBookDeltaStatus::APPLIED, $materializer->applyDelta($delta)->status());
        // The same delta again (its checksum aside, keys in another order): a replay.
        $replay = array_reverse($delta, true);
        $replay['checksum'] = 123;
        self::assertSame(OkxPaperBookDeltaStatus::REPLAYED, $materializer->applyDelta($replay)->status());
        // The same sequence with other content: an identity conflict.
        $conflict = $delta;
        $conflict['bids'] = [['2999.9', '4', '0', '1']];
        try {
            $materializer->applyDelta($conflict);
            self::fail('A conflicting repeat of a sequence must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }
        // A row CanonicalJson rejects is still rejected when it arrives.
        $unusual = [...$delta, 'prevSeqId' => '11', 'seqId' => '12', 'extra' => [1 => 'a', 0 => 'b']];
        try {
            $materializer->applyDelta($unusual);
            self::fail('A delta identity CanonicalJson rejects must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_canonical_json_ambiguous_integer_key_map', $exception->getMessage());
        }
        self::assertSame('11', $materializer->sourceSequence());
    }

    /** @return array<string, mixed> */
    private static function snapshot(int $sequence, int $depth): array
    {
        $asks = [];
        $bids = [];
        for ($index = 0; $index < $depth; ++$index) {
            $asks[] = [self::price(3_000_0 + 10 + $index), (string) mt_rand(1, 900), '0', (string) mt_rand(1, 9)];
            $bids[] = [self::price(3_000_0 - $index), (string) mt_rand(1, 900), '0', (string) mt_rand(1, 9)];
        }

        return ['asks' => $asks, 'bids' => $bids, 'ts' => '1784970300000', 'seqId' => (string) $sequence, 'prevSeqId' => '-1'];
    }

    /** @return array<string, mixed> */
    private static function adversarialDelta(BookReplay $replay): array
    {
        $bestBid = $replay->bestTick(true);
        $bestAsk = $replay->bestTick(false);
        $asks = [];
        $bids = [];
        foreach (range(1, mt_rand(1, 8)) as $unused) {
            $side = mt_rand(0, 1) === 0;
            $tick = match (mt_rand(0, 9)) {
                // The best level itself: resized or deleted.
                0, 1 => $side ? $bestBid : $bestAsk,
                // Just behind the best, or a new best.
                2, 3 => $side ? $bestBid + mt_rand(-3, 2) : $bestAsk + mt_rand(-2, 3),
                // Crossing the other side: the whole delta must be rejected.
                4 => $side ? $bestAsk + mt_rand(0, 2) : $bestBid - mt_rand(0, 2),
                // Far outside the 400 levels.
                5 => $side ? $bestBid - mt_rand(500, 5_000) : $bestAsk + mt_rand(500, 5_000),
                default => $side ? $bestBid - mt_rand(0, 60) : $bestAsk + mt_rand(0, 60),
            };
            $tick = max(1, $tick);
            $size = match (mt_rand(0, 5)) {
                0, 1 => '0',
                default => (string) mt_rand(1, 900),
            };
            // Numerically equal prices of another scale are distinct book keys.
            $price = mt_rand(0, 15) === 0 ? self::price($tick) . '0' : self::price($tick);
            // An order count of 0 on a live level is rejected.
            $orderCount = mt_rand(0, 60) === 0 ? '0' : (string) mt_rand(1, 9);
            $level = [$price, $size, '0', $orderCount];
            if ($side) {
                $bids[] = $level;
            } else {
                $asks[] = $level;
            }
            if (mt_rand(0, 25) === 0) {
                // Deleted then re-inserted in the same delta: it moves to the end.
                $reinsert = [$price, (string) mt_rand(1, 900), '0', '1'];
                if ($side) {
                    $bids[] = [$price, '0', '0', '0'];
                    $bids[] = $reinsert;
                } else {
                    $asks[] = [$price, '0', '0', '0'];
                    $asks[] = $reinsert;
                }
            }
        }

        return [
            'asks' => $asks,
            'bids' => $bids,
            'ts' => (string) (1784970300000 + $replay->sequence),
            'prevSeqId' => (string) $replay->sequence,
            'seqId' => (string) ($replay->sequence + 1),
        ];
    }

    private static function price(int $tick): string
    {
        return intdiv($tick, 10) . '.' . ($tick % 10);
    }
}
