<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Fake\FakeAccountLedgerOrigin;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeFundingModel;
use App\Exchange\Fake\FakeFundingModelConfig;
use App\Exchange\Fake\FakeFundingSchedule;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakePortfolioSource;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeReservationDescriptor;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntime;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperCanonicalFakePortfolioSource::class)]
final class PaperCanonicalFakePortfolioSourceTest extends TestCase
{
    public function testFreshModernCellProducesExactEmptyCanonicalSnapshot(): void
    {
        $root = $this->root('fresh');
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $clock = new MockClock('2026-08-10T12:00:00Z');
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());

            $snapshot = (new PaperCanonicalFakePortfolioSource($clock))
                ->snapshot($runtime, $effect->admissionProof->policy);

            self::assertEquals($effect->reservation->scope, $snapshot->scope);
            self::assertSame('paper_canonical_fake_private_portfolio', $snapshot->source);
            self::assertSame('1.0.0', $snapshot->sourceVersion);
            self::assertSame(100000.0, $snapshot->equityQuote);
            self::assertSame(0.0, $snapshot->realizedNetPnlQuote);
            self::assertSame(0.0, $snapshot->unrealizedNetPnlQuote);
            self::assertSame(0, $snapshot->openPositions);
            self::assertSame(0, $snapshot->pendingEntries);
            self::assertSame(0.0, $snapshot->openNotionalQuote);
            self::assertSame(0.0, $snapshot->pendingNotionalQuote);
            self::assertSame(0.0, $snapshot->reservedRiskQuote);
            self::assertSame([], $snapshot->activeDecisionKeys);
            self::assertSame($runtime->stateStore->privateStateSnapshot()->stateRevision, $snapshot->stateVersion);
            self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->inputHash);
        } finally {
            $this->removeRoot($root);
        }
    }

    public function testRestingCanonicalEntryCountsOnePendingReservation(): void
    {
        $root = $this->root('pending');
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $clock = new MockClock('2026-08-10T12:00:00Z');
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            (new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock))
                ->dispatch($runtime, $effect);

            $snapshot = (new PaperCanonicalFakePortfolioSource($clock))
                ->snapshot($runtime, $effect->admissionProof->policy);

            self::assertSame(100000.0, $snapshot->equityQuote);
            self::assertSame(0, $snapshot->openPositions);
            self::assertSame(1, $snapshot->pendingEntries);
            self::assertEqualsWithDelta($effect->reservation->reservedNotionalQuote, $snapshot->pendingNotionalQuote, 1.0e-12);
            self::assertEqualsWithDelta($effect->reservation->reservedRiskQuote, $snapshot->reservedRiskQuote, 1.0e-12);
            self::assertSame([$effect->decisionKey], $snapshot->activeDecisionKeys);
        } finally {
            $this->removeRoot($root);
        }
    }

    public function testFilledFundedAndMarkedPositionIsRestartStableAndNet(): void
    {
        $root = $this->root('filled');
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01);
        $clock = new MockClock('2026-08-10T12:00:00Z');
        $cell = $this->cell($effect->provenance);
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($cell);
            $runtime->applyMarketEvent($this->topOfBook());
            $dispatcher = new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock);
            $dispatcher->dispatch($runtime, $effect);
            $runtime->applyMarketEvent($this->topOfBook('100.08', '100.09', '2'));
            $position = $runtime->stateStore->getOpenPositions('BTCUSDT')[0] ?? null;
            self::assertNotNull($position);

            (new FakeFundingModel(FakeFundingModelConfig::v1(), $clock))->settle(
                new FakeFundingSchedule(
                    symbol: 'BTCUSDT',
                    side: $position->side,
                    fundingRate: '0.0001',
                    rateIntervalSeconds: 28800,
                    appliedIntervalSeconds: 28800,
                    currency: 'USDT',
                    dueAt: \DateTimeImmutable::createFromInterface($clock->now()),
                ),
                $position,
                $runtime->stateStore,
            );
            $source = new PaperCanonicalFakePortfolioSource($clock);
            $snapshot = $source->snapshot($runtime, $effect->admissionProof->policy);
            $restoredRuntime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($cell);
            $restored = $source->snapshot($restoredRuntime, $effect->admissionProof->policy);

            self::assertSame(1, $snapshot->openPositions);
            self::assertSame(0, $snapshot->pendingEntries);
            self::assertLessThan(0.0, $snapshot->realizedNetPnlQuote);
            self::assertEqualsWithDelta(
                100000.0 + $snapshot->realizedNetPnlQuote + $snapshot->unrealizedNetPnlQuote,
                $snapshot->equityQuote,
                1.0e-12,
            );
            self::assertEqualsWithDelta(
                $position->size * 100.085 * 0.01,
                $snapshot->openNotionalQuote,
                1.0e-12,
            );
            self::assertEquals($snapshot, $restored);

            $runtime->applyMarketEvent($this->topOfBook('100.18', '100.19', '3'));
            $remarked = $source->snapshot($runtime, $effect->admissionProof->policy);
            self::assertGreaterThan($snapshot->stateVersion, $remarked->stateVersion);
            self::assertNotSame($snapshot->inputHash, $remarked->inputHash);
            self::assertGreaterThan($snapshot->unrealizedNetPnlQuote, $remarked->unrealizedNetPnlQuote);
        } finally {
            $this->removeRoot($root);
        }
    }

    public function testPartialFillCountsOneDecisionAcrossPendingAndOpenExposure(): void
    {
        $root = $this->root('partial');
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $clock = new MockClock('2026-08-10T12:00:00Z');
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            $placed = (new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock))
                ->dispatch($runtime, $effect);
            self::assertNotNull($placed->execution->exchangeOrderId);
            $partial = $this->scenario($runtime)->fillOrder(
                $placed->execution->exchangeOrderId,
                0.1,
                100.1,
            );
            self::assertNotNull($partial);

            $snapshot = (new PaperCanonicalFakePortfolioSource($clock))
                ->snapshot($runtime, $effect->admissionProof->policy);

            self::assertSame(1, $snapshot->openPositions);
            self::assertSame(1, $snapshot->pendingEntries);
            self::assertSame([$effect->decisionKey], $snapshot->activeDecisionKeys);
            self::assertEqualsWithDelta($effect->reservation->reservedRiskQuote, $snapshot->reservedRiskQuote, 1.0e-12);
            self::assertEqualsWithDelta(
                $partial->remainingQuantity * 100.1,
                $snapshot->pendingNotionalQuote,
                1.0e-12,
            );
            self::assertEqualsWithDelta(0.1 * 100.1, $snapshot->openNotionalQuote, 1.0e-12);
        } finally {
            $this->removeRoot($root);
        }
    }

    public function testForgedActiveReservationFailsClosed(): void
    {
        $root = $this->root('forged');
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $clock = new MockClock('2026-08-10T12:00:00Z');
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            $placed = (new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock))
                ->dispatch($runtime, $effect);
            self::assertNotNull($placed->execution->exchangeOrderId);
            $order = $runtime->adapter->getOrder('BTCUSDT', $placed->execution->exchangeOrderId);
            self::assertNotNull($order);
            $metadata = $order->metadata;
            $metadata[PaperCanonicalFakeReservationDescriptor::METADATA_KEY] = '{}';
            $runtime->stateStore->saveOrder($this->withMetadata($order, $metadata));

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('paper_canonical_fake_portfolio_snapshot_invalid');
            (new PaperCanonicalFakePortfolioSource($clock))
                ->snapshot($runtime, $effect->admissionProof->policy);
        } finally {
            $this->removeRoot($root);
        }
    }

    public function testOrphanProtectionFailsClosed(): void
    {
        $root = $this->root('orphan-protection');
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $clock = new MockClock('2026-08-10T12:00:00Z');
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            (new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock))
                ->dispatch($runtime, $effect);
            $runtime->applyMarketEvent($this->topOfBook('100.08', '100.09', '2'));
            $position = $runtime->stateStore->getOpenPositions('BTCUSDT')[0] ?? null;
            self::assertNotNull($position);
            $runtime->stateStore->removePosition($position->symbol, $position->side);

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('paper_canonical_fake_portfolio_snapshot_invalid');
            (new PaperCanonicalFakePortfolioSource($clock))
                ->snapshot($runtime, $effect->admissionProof->policy);
        } finally {
            $this->removeRoot($root);
        }
    }

    public function testFutureCanonicalMonetaryEventFailsClosed(): void
    {
        $root = $this->root('future-ledger');
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $clock = new MockClock('2026-08-10T12:00:00Z');
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            (new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock))
                ->dispatch($runtime, $effect);
            $runtime->applyMarketEvent($this->topOfBook('100.08', '100.09', '2'));
            $fill = $runtime->stateStore->events('order.filled')[0] ?? null;
            self::assertNotNull($fill);
            $payload = $fill->payload;
            unset($payload['event_sequence']);
            $runtime->stateStore->appendEvent(new FakeExchangeEvent(
                $fill->type,
                $fill->symbol,
                new \DateTimeImmutable('2026-08-11T12:00:00Z'),
                $payload,
            ));

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('paper_canonical_fake_portfolio_snapshot_invalid');
            (new PaperCanonicalFakePortfolioSource($clock))
                ->snapshot($runtime, $effect->admissionProof->policy);
        } finally {
            $this->removeRoot($root);
        }
    }

    public function testMissingAuthenticatedAccountOriginFailsClosed(): void
    {
        $root = $this->root('missing-origin');
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $clock = new MockClock('2026-08-10T12:00:00Z');
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, $clock))->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            $raw = file_get_contents($runtime->statePath);
            self::assertIsString($raw);
            $envelope = unserialize($raw, ['allowed_classes' => true]);
            self::assertIsArray($envelope);
            $balance = $envelope['payload']['balances']['USDT'] ?? null;
            self::assertInstanceOf(ExchangeBalanceDto::class, $balance);
            $metadata = $balance->metadata;
            unset($metadata[FakeAccountLedgerOrigin::METADATA_KEY]);
            $envelope['payload']['balances']['USDT'] = new ExchangeBalanceDto(
                $balance->exchange,
                $balance->marketType,
                $balance->currency,
                $balance->available,
                $balance->total,
                $balance->equity,
                $balance->unrealizedPnl,
                $metadata,
            );
            $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
            self::assertIsInt(file_put_contents($runtime->statePath, serialize($envelope)));

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('paper_canonical_fake_portfolio_snapshot_invalid');
            (new PaperCanonicalFakePortfolioSource($clock))
                ->snapshot($runtime, $effect->admissionProof->policy);
        } finally {
            $this->removeRoot($root);
        }
    }

    /** @param array<string,string> $provenance */
    private function cell(array $provenance): PaperExecutionCell
    {
        $network = PaperMarketDataNetwork::from($provenance['paper_network']);
        $venue = PaperMarketDataVenue::from($provenance['market_data_venue']);

        return PaperExecutionCell::createModern(
            $network,
            $venue,
            $provenance['configuration_snapshot_id'],
            PaperModernStrategyIdentity::fromDurableIdentity(
                $network,
                $venue,
                $provenance['mode_id'],
                $provenance['mode_version'],
                $provenance['setup_id'],
                $provenance['setup_version'],
                $provenance['side'],
                $provenance['config_hash'],
                $provenance['condition_catalog_hash'],
            ),
            $provenance['run_id'],
        );
    }

    private function topOfBook(
        string $bid = '100.095',
        string $ask = '100.105',
        string $ordinal = '1',
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-10T11:59:45Z'),
            new \DateTimeImmutable('2026-08-10T11:59:46Z'),
            $ordinal,
            ['bid_price' => $bid, 'ask_price' => $ask],
        );
    }

    /** @param array<string,mixed> $metadata */
    private function withMetadata(ExchangeOrderDto $order, array $metadata): ExchangeOrderDto
    {
        return new ExchangeOrderDto(
            $order->exchange,
            $order->marketType,
            $order->symbol,
            $order->exchangeOrderId,
            $order->clientOrderId,
            $order->side,
            $order->positionSide,
            $order->orderType,
            $order->status,
            $order->quantity,
            $order->filledQuantity,
            $order->remainingQuantity,
            $order->price,
            $order->averagePrice,
            $order->stopPrice,
            $order->reduceOnly,
            $order->postOnly,
            $order->timeInForce,
            $order->createdAt,
            $order->updatedAt,
            $metadata,
        );
    }

    private function root(string $suffix): string
    {
        return sys_get_temp_dir() . '/paper-canonical-portfolio-' . $suffix . '-' . bin2hex(random_bytes(6));
    }

    private function scenario(PaperFakeRuntime $runtime): FakeExchangeScenarioService
    {
        $orderBook = (new \ReflectionProperty(PaperFakeRuntime::class, 'orderBook'))->getValue($runtime);
        $matchingEngine = (new \ReflectionProperty(PaperFakeRuntime::class, 'matchingEngine'))->getValue($runtime);
        self::assertInstanceOf(FakeExchangeOrderBook::class, $orderBook);
        self::assertInstanceOf(FakeExchangeMatchingEngine::class, $matchingEngine);

        return new FakeExchangeScenarioService($runtime->stateStore, $orderBook, $matchingEngine);
    }

    private function removeRoot(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        foreach (glob($root . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($root);
    }
}
