<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\PaperExecutionConsumer;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperExecutionConsumer::class)]
final class PaperExecutionConsumerTest extends TestCase
{
    public function testLiveAndReplayUseExplicitDurablePositions(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $cell = PaperExecutionCell::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'sha256:' . str_repeat('e', 64), 'scalper_micro', 'run-1');
        $coordinator = new class($store) implements PaperEventCoordinatorInterface {
            /** @var list<int> */ public array $positions = [];
            public function __construct(private InMemoryPaperExecutionStore $store) {}
            public function consumeAt(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, int $sourcePosition, PaperMarketEvent $event): void
            {
                $this->positions[] = $sourcePosition;
                $this->store->claimSource($cell, $sourcePosition, $event);
            }
        };
        $consumer = new PaperExecutionConsumer($coordinator, $store, $cell, PaperProfileEligibility::REFERENCE_ONLY);

        $consumer->consume('dataset-1', $this->event(0));
        $consumer->consume('dataset-1', $this->event(1));
        $consumer->consumeReplay('dataset-1', 1, $this->event(1));

        self::assertSame([0, 1, 1], $coordinator->positions);
    }

    private function event(int $second): PaperMarketEvent
    {
        $timestamp = new \DateTimeImmutable(sprintf('2026-08-01T10:00:%02dZ', $second));
        return PaperMarketEvent::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'BTCUSDT', PaperMarketDataChannel::TOP_OF_BOOK, $timestamp, $timestamp, (string) $second, ['bid_price' => '24999', 'ask_price' => '25001']);
    }
}
