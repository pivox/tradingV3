<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\Live\OkxPaperBookDeltaStatus;
use App\Trading\Paper\Okx\Live\OkxPaperOrderBookMaterializer;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use PHPUnit\Framework\TestCase;

/**
 * One book replayed through the incremental materializer and through the complete
 * re-materialization (the reference), compared after every delta.
 */
final class BookReplay
{
    public int $sequence = 0;
    private OkxPaperOrderBookMaterializer $materializer;
    /** @var array<array-key, array{price: string, size: string, raw_field_3: string, order_count: string}> */
    private array $bids = [];
    /** @var array<array-key, array{price: string, size: string, raw_field_3: string, order_count: string}> */
    private array $asks = [];
    private bool $hasBook = false;

    public function __construct()
    {
        $this->materializer = new OkxPaperOrderBookMaterializer();
    }

    public function hasBook(): bool
    {
        return $this->hasBook;
    }

    /** @param array<string, mixed> $snapshot */
    public function snapshot(array $snapshot): void
    {
        $this->materializer->replaceSnapshot($snapshot);
        $this->bids = self::levels($snapshot['bids']);
        $this->asks = self::levels($snapshot['asks']);
        $this->sequence = (int) $snapshot['seqId'];
        $this->hasBook = true;
    }

    /** The best price in ticks of 0.1 (an approximation is enough to aim the deltas). */
    public function bestTick(bool $bid): int
    {
        $prices = array_map('floatval', array_keys($bid ? $this->bids : $this->asks));

        return (int) round(($bid ? max($prices) : min($prices)) * 10);
    }

    /** @param array<string, mixed> $delta */
    public function delta(array $delta, int &$applied, int &$rejected): void
    {
        $bids = $this->bids;
        $asks = $this->asks;
        foreach (self::levels($delta['bids'] ?? [], true) as $level) {
            self::update($bids, $level);
        }
        foreach (self::levels($delta['asks'] ?? [], true) as $level) {
            self::update($asks, $level);
        }
        $reference = null;
        try {
            $completeState = (new \ReflectionMethod(OkxPaperOrderBookMaterializer::class, 'completeState'))
                ->invoke(null, $bids, $asks, $delta['ts'] ?? null, (string) $delta['prevSeqId'], (string) $delta['seqId']);
            \assert(\is_array($completeState));
            $reference = OkxMaterializedBookState::fromAppliedDelta($completeState);
        } catch (\InvalidArgumentException $exception) {
            TestCase::assertSame('okx_paper_materialized_order_book_invalid', $exception->getMessage());
        }
        try {
            $result = $this->materializer->applyDelta($delta);
        } catch (\InvalidArgumentException $exception) {
            TestCase::assertSame('okx_paper_materialized_order_book_invalid', $exception->getMessage());
            TestCase::assertNull($reference, 'The incremental book rejected a delta the complete one accepts.');
            TestCase::assertSame((string) $this->sequence, $this->materializer->sourceSequence());
            ++$rejected;

            return;
        }
        TestCase::assertNotNull($reference, 'The incremental book accepted a delta the complete one rejects.');
        TestCase::assertSame(OkxPaperBookDeltaStatus::APPLIED, $result->status());
        $state = $result->materializedState();
        TestCase::assertSame($reference->bestBid(), $state->bestBid());
        TestCase::assertSame($reference->bestAsk(), $state->bestAsk());
        TestCase::assertSame($reference->sourceSequence, $state->sourceSequence);
        TestCase::assertSame($reference->sourcePreviousSequence, $state->sourcePreviousSequence);
        TestCase::assertEquals($reference->exchangeTimestamp, $state->exchangeTimestamp);
        TestCase::assertSame($reference->bids(), $state->bids());
        TestCase::assertSame($reference->asks(), $state->asks());
        $this->bids = $bids;
        $this->asks = $asks;
        $this->sequence = (int) $delta['seqId'];
        ++$applied;
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return array<array-key, array{price: string, size: string, raw_field_3: string, order_count: string}>
     */
    private static function levels(array $rows, bool $list = false): array
    {
        $levels = [];
        foreach ($rows as $row) {
            $level = ['price' => (string) $row[0], 'size' => (string) $row[1], 'raw_field_3' => (string) $row[2], 'order_count' => (string) $row[3]];
            if ($list) {
                $levels[] = $level;
            } else {
                $levels[$level['price']] = $level;
            }
        }

        return $levels;
    }

    /**
     * @param array<array-key, array{price: string, size: string, raw_field_3: string, order_count: string}> $side
     * @param array{price: string, size: string, raw_field_3: string, order_count: string}                   $level
     */
    private static function update(array &$side, array $level): void
    {
        if (trim($level['size'], '0.') === '') {
            unset($side[$level['price']]);
        } else {
            $side[$level['price']] = $level;
        }
    }
}
