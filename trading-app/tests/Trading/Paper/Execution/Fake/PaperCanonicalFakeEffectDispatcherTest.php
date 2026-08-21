<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeOrderCreated;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use App\TradeEntry\Dto\ExecutionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperCanonicalFakeEffectDispatcher::class)]
final class PaperCanonicalFakeEffectDispatcherTest extends TestCase
{
    public function testDuplicateCanonicalEffectMutatesOnlyItsFakeCellOnce(): void
    {
        $root = sys_get_temp_dir() . '/paper_canonical_fake_' . bin2hex(random_bytes(6));
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $previousPrecision = ini_get('precision');
        self::assertIsString($previousPrecision);
        try {
            self::assertNotFalse(ini_set('precision', '2'));
            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-10T12:00:00Z')))
                ->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            $dispatcher = new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer());

            $first = $dispatcher->dispatch($runtime, $effect);
            self::assertTrue($runtime->adapter->setLeverage('BTCUSDT', 2, 'isolated'));
            $replayCursor = $runtime->eventCursor();
            $second = $dispatcher->dispatch($runtime, $effect);

            $entries = array_values(array_filter(
                $runtime->adapter->getOrdersSnapshot('BTCUSDT'),
                static fn ($order): bool => !$order->reduceOnly,
            ));
            self::assertCount(1, $entries);
            self::assertSame(
                ExecutionResult::STATUS_SUBMITTED_PROTECTED,
                $first->execution->status,
                json_encode($first->execution->raw, JSON_THROW_ON_ERROR),
            );
            self::assertSame($effect->orderIntentIdentity['client_order_id'], $first->execution->clientOrderId);
            self::assertNotNull($first->execution->exchangeOrderId);
            self::assertNotEmpty($first->events);
            self::assertFalse($first->idempotentReplay);
            self::assertInstanceOf(ExchangeOrderCreated::class, $first->events[0]);
            self::assertSame(
                $effect->lineage->conditionCatalogHash,
                $first->events[0]->order()->metadata['condition_catalog_hash'] ?? null,
            );
            self::assertSame(
                $effect->plan->planHash,
                $first->execution->raw['order']['metadata']['plan_hash'] ?? null,
            );
            self::assertSame([], $second->events);
            self::assertTrue($second->idempotentReplay);
            self::assertSame([], $runtime->eventsSince($replayCursor));
            self::assertSame(
                ['leverage' => 2, 'margin_mode' => 'isolated'],
                $runtime->stateStore->getLeverageSetting('BTCUSDT'),
            );
            self::assertSame($first->execution->exchangeOrderId, $second->execution->exchangeOrderId);
            self::assertSame($effect->plan->planHash, $entries[0]->metadata['plan_hash'] ?? null);
            self::assertSame($effect->plan->modeId, $entries[0]->metadata['mode_id'] ?? null);
            self::assertSame($effect->plan->setupId, $entries[0]->metadata['setup_id'] ?? null);
            self::assertSame(
                'paper_canonical_fake_dispatcher',
                $entries[0]->metadata['canonical_dispatch_source'] ?? null,
            );
            self::assertSame('0.249', $entries[0]->metadata['quantity_decimal'] ?? null);
            self::assertSame('100.1', $entries[0]->metadata['price_decimal'] ?? null);
            self::assertSame('98.6', $entries[0]->metadata['attached_stop_loss_price_decimal'] ?? null);
            self::assertSame('102.8', $entries[0]->metadata['attached_take_profit_price_decimal'] ?? null);
        } finally {
            ini_set('precision', $previousPrecision);
            $this->removeRuntime($root);
        }
    }

    public function testCompensatedProtectionFailureIsFailedAndFillKeepsModernProvenance(): void
    {
        $root = sys_get_temp_dir() . '/paper_canonical_fake_compensation_' . bin2hex(random_bytes(6));
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        try {
            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-10T12:00:00Z')))
                ->forCell($this->cell($effect->provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            $dispatcher = new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer());
            self::assertSame(
                ExecutionResult::STATUS_SUBMITTED_PROTECTED,
                $dispatcher->dispatch($runtime, $effect)->execution->status,
            );

            $rawCreated = array_values(array_filter(
                $runtime->eventsSince(0),
                static fn ($event): bool => $event->type === 'order.created',
            ))[0] ?? null;
            self::assertInstanceOf(FakeExchangeEvent::class, $rawCreated);
            $fillEvents = array_values(array_filter(
                (new FakeExchangeEventNormalizer())->normalize(new FakeExchangeEvent(
                    'order.filled',
                    'BTCUSDT',
                    new \DateTimeImmutable('2026-08-10T12:00:01Z'),
                    $rawCreated->payload,
                )),
                static fn ($event): bool => $event instanceof ExchangeFillReceived,
            ));
            self::assertNotEmpty($fillEvents);
            foreach ($fillEvents as $event) {
                self::assertInstanceOf(ExchangeFillReceived::class, $event);
                foreach (PaperExecutionProvenance::MODERN_KEYS as $key) {
                    self::assertSame($effect->provenance[$key], $event->fill()->metadata[$key] ?? null);
                }
            }

            $entry = $runtime->stateStore->getOrderByClientOrderId('BTCUSDT', 'paper-modern-cid-001');
            self::assertInstanceOf(ExchangeOrderDto::class, $entry);
            $runtime->stateStore->saveOrder(new ExchangeOrderDto(
                exchange: $entry->exchange,
                marketType: $entry->marketType,
                symbol: $entry->symbol,
                exchangeOrderId: $entry->exchangeOrderId,
                clientOrderId: $entry->clientOrderId,
                side: $entry->side,
                positionSide: $entry->positionSide,
                orderType: $entry->orderType,
                status: ExchangeOrderStatus::FILLED,
                quantity: $entry->quantity,
                filledQuantity: $entry->quantity,
                remainingQuantity: 0.0,
                price: $entry->price,
                averagePrice: $entry->price,
                stopPrice: $entry->stopPrice,
                reduceOnly: $entry->reduceOnly,
                postOnly: $entry->postOnly,
                timeInForce: $entry->timeInForce,
                createdAt: $entry->createdAt,
                updatedAt: new \DateTimeImmutable('2026-08-10T12:00:01Z'),
                metadata: array_replace($entry->metadata, [
                    'protection_status' => 'rejected',
                    'protection_reject_reason' => 'protection_rejected_by_scenario',
                    'compensation_status' => 'completed',
                    'compensation_outcome' => 'position_closed',
                ]),
            ));
            $replayCursor = $runtime->eventCursor();
            $replay = $dispatcher->dispatch($runtime, $effect);
            self::assertTrue($replay->idempotentReplay);
            self::assertSame([], $replay->events);
            self::assertSame([], $runtime->eventsSince($replayCursor));
            self::assertSame(ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED, $replay->execution->status);
            self::assertSame('protection_rejected_by_scenario', $replay->execution->raw['reason'] ?? null);
            self::assertSame([], $runtime->adapter->getOpenPositions('BTCUSDT'));
        } finally {
            $this->removeRuntime($root);
        }
    }

    public function testCrossCellCanonicalEffectFailsBeforeFakeMutation(): void
    {
        $root = sys_get_temp_dir() . '/paper_canonical_fake_scope_' . bin2hex(random_bytes(6));
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        try {
            $provenance = $effect->provenance;
            $provenance['run_id'] = 'another-run';
            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-10T12:00:00Z')))
                ->forCell($this->cell($provenance));
            $runtime->applyMarketEvent($this->topOfBook());
            $cursor = $runtime->eventCursor();

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('paper_canonical_fake_effect_invalid');
            try {
                (new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer()))
                    ->dispatch($runtime, $effect);
            } finally {
                self::assertSame([], $runtime->adapter->getOrdersSnapshot('BTCUSDT'));
                self::assertSame([], $runtime->eventsSince($cursor));
            }
        } finally {
            $this->removeRuntime($root);
        }
    }

    public function testCanonicalDispatcherHasNoLegacyPlanDependency(): void
    {
        $path = (new \ReflectionClass(PaperCanonicalFakeEffectDispatcher::class))->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringNotContainsString('PreparedTradeEntry', $source);
        self::assertStringNotContainsString('OrderPlanModel', $source);
        self::assertStringNotContainsString('ExchangeExecutionService', $source);
    }

    /** @param array<string, string> $provenance */
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
        string $bidPrice = '100.095',
        string $askPrice = '100.105',
        string $sourceOrdinal = '1',
    ): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-10T11:59:45Z'),
            new \DateTimeImmutable('2026-08-10T11:59:46Z'),
            $sourceOrdinal,
            ['bid_price' => $bidPrice, 'ask_price' => $askPrice],
        );
    }

    private function removeRuntime(string $root): void
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
