<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

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

    /**
     * @return array{PaperCanonicalExecutionCostSource, PaperExecutionCell, PaperMarketEvent, CanonicalExecutionPolicy}
     */
    private function context(): array
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading',
            '1.1.0',
            'day_trading.trend_continuation.long',
            '1.1.0',
            'okx',
            'mainnet',
            'long',
            ShadowExecutionCapability::Paper,
        ));
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
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
        $book = $this->book();
        $funding = $this->funding();
        $trigger = $this->trigger();
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$book, $funding, $trigger]);
        $clock = new PaperReplayClock($trigger->receivedTimestamp);

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

    private function book(): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-01T10:00:58Z'),
            new \DateTimeImmutable('2026-08-01T10:00:59Z'),
            '1',
            [
                'native_symbol' => 'BTC-USDT-SWAP',
                'bid_price' => '99.99',
                'bid_size_contracts' => '5',
                'bid_order_count' => '2',
                'ask_price' => '100.01',
                'ask_size_contracts' => '4',
                'ask_order_count' => '3',
                'source_seq_id' => '1',
                'source_prev_seq_id' => null,
                'source_epoch' => 1,
                'origin' => 'ws_books',
            ],
        );
    }

    private function funding(): PaperMarketEvent
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
                'funding_rate' => '0.0001',
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

    private function trigger(): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_15M,
            new \DateTimeImmutable('2026-08-01T10:00:00Z'),
            new \DateTimeImmutable('2026-08-01T10:01:01Z'),
            '3',
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
