<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalExecutionCostSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalFundingSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalOrderBookSource;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalTargetCostSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalExecutionCostSource::class)]
final class PaperCanonicalExecutionCostSourceTest extends TestCase
{
    public function testBuildsCompleteCostSnapshotFromCanonicalEvidence(): void
    {
        [$source, $cell, $trigger, $policy] = $this->context();

        $costs = $source->snapshotFor($cell, $trigger, $policy);

        self::assertNotNull($costs);
        self::assertSame('okx', $costs->exchange);
        self::assertSame('mainnet', $costs->environment);
        self::assertSame('BTCUSDT', $costs->symbol);
        self::assertSame('perpetual', $costs->marketType);
        self::assertSame($policy->configHash, $costs->configHash);
        self::assertSame('maker', $costs->entryLiquidityRole);
        self::assertSame('taker', $costs->stopLiquidityRole);
        self::assertSame('order_book', $costs->entrySpreadSource);
        self::assertEqualsWithDelta(0.0002, $costs->entrySpreadRate, 1.0e-12);
        self::assertSame('execution_model', $costs->entrySlippageSource);
        self::assertSame(0.0, $costs->entrySlippageRate);
        self::assertSame(0.0005, $costs->stopSlippageRate);
        self::assertSame('venue_schedule', $costs->fundingSource);
        self::assertSame(0.0001, $costs->fundingRate);
        self::assertSame(['tp1'], array_map(
            static fn (CanonicalTargetCostSnapshot $target): string => $target->targetId,
            $costs->targets,
        ));
        self::assertSame([0.0005], array_map(
            static fn (CanonicalTargetCostSnapshot $target): float => (float) $target->slippageRate,
            $costs->targets,
        ));
        self::assertSame('2026-08-01T10:00:58.000000Z', $costs->observedAt->format('Y-m-d\TH:i:s.u\Z'));
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $costs->inputHash);
    }

    public function testReturnsNullWithoutBookOrFundingEvidence(): void
    {
        foreach ([[false, true], [true, false]] as [$withBook, $withFunding]) {
            [$source, $cell, $trigger, $policy] = $this->context(
                withBook: $withBook,
                withFunding: $withFunding,
            );

            self::assertNull($source->snapshotFor($cell, $trigger, $policy));
        }
    }

    public function testRejectsPolicyIdentityDriftBeforeReadingEvidence(): void
    {
        [$source, $cell, $trigger] = $this->context();
        [$foreignPolicy] = $this->publishedPolicy(
            'scalping',
            'scalping.trend_continuation.long',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_execution_cost_identity_mismatch');

        $source->snapshotFor($cell, $trigger, $foreignPolicy);
    }

    public function testRejectsUnapprovedCostSourceEvenWhenTheConfigIsReauthenticated(): void
    {
        [$policy, $snapshot] = $this->forgedPolicy(static function (array &$payload): void {
            $payload['setup']['ast']['execution']['cost_contract']['value']['entry_spread_source'] = 'fixture';
        });
        [$source, $cell, $trigger] = $this->context(policy: $policy, snapshot: $snapshot);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_execution_cost_contract_mismatch');

        $source->snapshotFor($cell, $trigger, $policy);
    }

    public function testRejectsStaleBookEvidence(): void
    {
        [$source, $cell, $trigger, $policy] = $this->context(
            bookExchangeTimestamp: '2026-08-01T09:59:00Z',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_execution_cost_book_stale');

        $source->snapshotFor($cell, $trigger, $policy);
    }

    public function testPropagatesFundingIntervalMismatch(): void
    {
        [$policy, $snapshot] = $this->forgedPolicy(static function (array &$payload): void {
            $payload['exchange']['funding']['interval'] = 'PT4H';
        });
        [$source, $cell, $trigger] = $this->context(policy: $policy, snapshot: $snapshot);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_funding_interval_mismatch');

        $source->snapshotFor($cell, $trigger, $policy);
    }

    public function testHashIsDeterministicAndSensitiveToEveryPublicEvidenceRoot(): void
    {
        [$source, $cell, $trigger, $policy] = $this->context();
        $first = $source->snapshotFor($cell, $trigger, $policy);
        $second = $source->snapshotFor($cell, $trigger, $policy);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->inputHash, $second->inputHash);

        [$fundingSource, $fundingCell, $fundingTrigger, $fundingPolicy] = $this->context(
            fundingRate: '-0.0001',
        );
        $changedFunding = $fundingSource->snapshotFor($fundingCell, $fundingTrigger, $fundingPolicy);
        self::assertNotNull($changedFunding);
        self::assertNotSame($first->inputHash, $changedFunding->inputHash);

        [$bookSource, $bookCell, $bookTrigger, $bookPolicy] = $this->context(
            bookSequence: '9',
            bidPrice: '199.98',
            askPrice: '200.02',
        );
        $changedBook = $bookSource->snapshotFor($bookCell, $bookTrigger, $bookPolicy);
        self::assertNotNull($changedBook);
        self::assertNotSame($first->inputHash, $changedBook->inputHash);
        self::assertEqualsWithDelta($first->entrySpreadRate, $changedBook->entrySpreadRate, 1.0e-12);

        [$targetPolicy, $targetSnapshot] = $this->forgedPolicy(static function (array &$payload): void {
            $payload['setup']['ast']['execution']['targets']['value'][0]['liquidity_role'] = 'maker';
        });
        [$targetSource, $targetCell, $targetTrigger] = $this->context(
            policy: $targetPolicy,
            snapshot: $targetSnapshot,
        );
        $changedTarget = $targetSource->snapshotFor($targetCell, $targetTrigger, $targetPolicy);
        self::assertNotNull($changedTarget);
        self::assertSame(0.0, $changedTarget->targets[0]->slippageRate);
        self::assertNotSame($first->inputHash, $changedTarget->inputHash);
    }

    public function testRejectsLegacyCellCrossScopeTriggerAndStaleTrigger(): void
    {
        [$source, $cell, $trigger, $policy] = $this->context();
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64),
            'regular',
            'paper-execution-cost-legacy',
        );
        try {
            $source->snapshotFor($legacy, $trigger, $policy);
            self::fail('Legacy cost cell was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_strategy_cell_identity_missing', $exception->getMessage());
        }

        $foreign = PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_15M,
            new \DateTimeImmutable('2026-08-01T10:00:00Z'),
            new \DateTimeImmutable('2026-08-01T10:01:01Z'),
            '3',
            ['bar' => '15m'],
        );
        try {
            $source->snapshotFor($cell, $foreign, $policy);
            self::fail('Cross-scope cost trigger was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_strategy_market_scope_mismatch', $exception->getMessage());
        }

        [$staleSource, $staleCell, $staleTrigger, $stalePolicy] = $this->context(appendNewerTrigger: true);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_not_current');
        $staleSource->snapshotFor($staleCell, $staleTrigger, $stalePolicy);
    }

    /**
     * @return array{PaperCanonicalExecutionCostSource, PaperExecutionCell, PaperMarketEvent, CanonicalExecutionPolicy}
     */
    private function context(
        bool $withBook = true,
        bool $withFunding = true,
        string $bookExchangeTimestamp = '2026-08-01T10:00:58Z',
        string $fundingRate = '0.0001',
        string $bookSequence = '1',
        string $bidPrice = '99.99',
        string $askPrice = '100.01',
        ?CanonicalExecutionPolicy $policy = null,
        ?EffectiveTradingConfigSnapshot $snapshot = null,
        bool $appendNewerTrigger = false,
    ): array
    {
        if ($policy === null || $snapshot === null) {
            [$policy, $snapshot] = $this->publishedPolicy();
        }
        $cell = PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                $policy->riskPolicy->modeId,
                $policy->riskPolicy->modeVersion,
                $policy->riskPolicy->setupId,
                $policy->riskPolicy->setupVersion,
                $policy->riskPolicy->side,
                $policy->configHash,
                (string) $snapshot->conditionCatalogHash,
            ),
            'paper-execution-cost-run',
        );
        $trigger = $this->trigger();
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $events = [];
        if ($withBook) {
            $events[] = $this->book($bookExchangeTimestamp, $bookSequence, $bidPrice, $askPrice);
        }
        if ($withFunding) {
            $events[] = $this->funding($fundingRate);
        }
        $events[] = $trigger;
        if ($appendNewerTrigger) {
            $events[] = $this->trigger('4', '2026-08-01T10:02:01Z');
        }
        $market->restore($events);
        $clock = new PaperReplayClock($trigger->receivedTimestamp);
        if ($appendNewerTrigger) {
            $clock->advanceTo(new \DateTimeImmutable('2026-08-01T10:02:01Z'));
        }

        return [
            new PaperCanonicalExecutionCostSource(
                new PaperCanonicalOrderBookSource($market, $clock),
                new PaperCanonicalFundingSource($market, $clock),
                $clock,
            ),
            $cell,
            $trigger,
            $policy,
        ];
    }

    /**
     * @return array{CanonicalExecutionPolicy, EffectiveTradingConfigSnapshot}
     */
    private function publishedPolicy(
        string $modeId = 'day_trading',
        string $setupId = 'day_trading.trend_continuation.long',
    ): array {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            $modeId,
            '1.1.0',
            $setupId,
            '1.1.0',
            'okx',
            'mainnet',
            'long',
            ShadowExecutionCapability::Paper,
        ));

        return [(new CanonicalExecutionPolicyCompiler())->compile($snapshot), $snapshot];
    }

    /**
     * @param callable(array<string, mixed>&): void $mutate
     * @return array{CanonicalExecutionPolicy, EffectiveTradingConfigSnapshot}
     */
    private function forgedPolicy(callable $mutate): array
    {
        [, $snapshot] = $this->publishedPolicy();
        $payload = $snapshot->payload();
        $mutate($payload);
        unset($payload['setup']['payload_hash']);
        $payload['setup']['payload_hash'] = $this->canonicalHash($payload['setup']);
        $forged = new EffectiveTradingConfigSnapshot(
            $snapshot->request,
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash(
                $payload,
                (string) $snapshot->conditionCatalogHash,
            ),
            $snapshot->conditionCatalogHash,
            $snapshot->orderedLayers(),
            $snapshot->provenance(),
        );

        return [(new CanonicalExecutionPolicyCompiler())->compile($forged), $forged];
    }

    /** @param array<string, mixed> $value */
    private function canonicalHash(array $value): string
    {
        $canonicalize = static function (mixed $node) use (&$canonicalize): mixed {
            if (!\is_array($node)) {
                return $node;
            }
            if (!array_is_list($node)) {
                ksort($node, \SORT_STRING);
            }
            foreach ($node as $key => $child) {
                $node[$key] = $canonicalize($child);
            }

            return $node;
        };

        return hash('sha256', json_encode(
            $canonicalize($value),
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function book(
        string $exchangeTimestamp = '2026-08-01T10:00:58Z',
        string $sequence = '1',
        string $bidPrice = '99.99',
        string $askPrice = '100.01',
    ): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable($exchangeTimestamp),
            new \DateTimeImmutable('2026-08-01T10:00:59Z'),
            $sequence,
            [
                'native_symbol' => 'BTC-USDT-SWAP',
                'bid_price' => $bidPrice,
                'bid_size_contracts' => '5',
                'bid_order_count' => '2',
                'ask_price' => $askPrice,
                'ask_size_contracts' => '4',
                'ask_order_count' => '3',
                'source_seq_id' => $sequence,
                'source_prev_seq_id' => null,
                'source_epoch' => 1,
                'origin' => 'ws_books',
            ],
        );
    }

    private function funding(string $rate = '0.0001'): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::FUNDING_RATE,
            new \DateTimeImmutable('2026-08-01T10:00:59.500Z'),
            new \DateTimeImmutable('2026-08-01T10:00:59.500Z'),
            '2',
            [
                'funding_schema_version' => 'paper-funding-rate.v1',
                'native_symbol' => 'BTC-USDT-SWAP',
                'instrument_type' => 'perpetual',
                'funding_rate' => $rate,
                'observed_at_ms' => '1785578459000',
                'funding_time_ms' => '1785607200000',
                'next_funding_time_ms' => '1785636000000',
                'funding_interval_seconds' => 28800,
                'method' => 'current_period',
                'formula_type' => 'withRate',
                'settlement_state' => 'settled',
                'source_epoch' => 1,
                'origin' => 'rest_public_funding_rate',
            ],
        );
    }

    private function trigger(
        string $sequence = '3',
        string $receivedTimestamp = '2026-08-01T10:01:01Z',
    ): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_15M,
            new \DateTimeImmutable('2026-08-01T10:00:00Z'),
            new \DateTimeImmutable($receivedTimestamp),
            $sequence,
            [
                'native_symbol' => 'BTC-USDT-SWAP',
                'bar' => '15m',
                'open' => '100',
                'high' => '101',
                'low' => '99',
                'close' => '100',
                'volume_contracts' => '10',
                'volume_base' => '1',
                'volume_quote' => '100',
                'confirmed' => true,
                'origin' => 'rest_history',
            ],
        );
    }
}
