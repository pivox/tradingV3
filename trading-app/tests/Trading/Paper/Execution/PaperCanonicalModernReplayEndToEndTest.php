<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volatility\Bollinger;
use App\Indicator\Core\Volume\Vwap;
use App\Kernel;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakePortfolioSource;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\PaperCrashPoint;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalExecutionCostSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalFundingSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalIndicatorDatasetBindingBuilder;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalIndicatorProjectionSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalIndicatorWindowSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalInstrumentSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalOrderBookSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalOrderPlanEvidenceSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceProvider;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyInputAssembler;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparation;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparationInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyRuntime;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Runtime\PaperDatabaseGuard;
use App\Trading\Paper\Runtime\PaperDatabaseInspection;
use App\Trading\Paper\Runtime\PaperDatabaseInspectorInterface;
use App\Trading\Paper\Runtime\PaperRuntimeGuard;
use App\TradingCore\Backtesting\Indicator\CanonicalFourHourAggregator;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjector;
use App\TradingCore\Backtesting\Indicator\CanonicalPhpIndicatorCalculator;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once __DIR__ . '/PaperExecutionCoordinatorTest.php';

#[CoversClass(PaperExecutionCoordinator::class)]
final class PaperCanonicalModernReplayEndToEndTest extends KernelTestCase
{
    private const DATASET_ID = 'paper-modern-representative-001';
    private const EVENTS_SHA256 = '4444444444444444444444444444444444444444444444444444444444444444';
    private const SOURCE_BUILD_VERSION = 'paper-dataset-recorder.v2';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    #[DataProvider('publicVenueProvider')]
    public function testRealModernDecisionAndFakeDispatchConvergeAfterCrashWithoutDuplicateOrder(
        string $venueId,
    ): void
    {
        $venue = PaperMarketDataVenue::from($venueId);
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $root = sys_get_temp_dir() . '/paper_modern_real_replay_' . $venueId . '_' . bin2hex(random_bytes(5));
        $triggerTime = new \DateTimeImmutable('2026-08-20T12:00:04Z');
        $clock = new PaperReplayClock($triggerTime);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $runtimeFactory = new PaperFakeRuntimeFactory($root, $clock, 'representative-modern-replay-seed');
        $resolver = new EffectiveTradingConfigResolver();
        $snapshot = $resolver->resolve(new EffectiveTradingConfigRequest(
            'day_trading',
            '1.1.0',
            'day_trading.trend_continuation.long',
            '1.1.0',
            $venueId,
            'mainnet',
            'long',
            ShadowExecutionCapability::Paper,
        ));
        $cell = PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET,
            $venue,
            $snapshot->toArray()['snapshot_hash'],
            PaperModernStrategyIdentity::fromResolvedSnapshot(
                PaperMarketDataNetwork::MAINNET,
                $venue,
                $snapshot,
            ),
            'paper-modern-e2e-run',
        );
        [$prefix, $trigger] = $this->representativeEvents($venue);
        $probeRoot = $root . '_probe';
        $probeMarket = new PaperMarketStateProjector(new PaperKlineProvider());
        $probeMarket->restore([...$prefix, $trigger]);
        $probePreparation = $this->canonicalPreparation(
            $probeMarket,
            $clock,
            new PaperFakeRuntimeFactory($probeRoot, $clock, 'representative-modern-replay-seed'),
            $resolver,
        );
        $probeDecision = $probePreparation->prepareFor(
            $cell,
            $trigger,
            self::DATASET_ID,
            self::EVENTS_SHA256,
            self::SOURCE_BUILD_VERSION,
        );
        self::assertNotNull($probeDecision, 'The representative prefix must produce a canonical decision.');
        self::assertSame('15m', $probeDecision->executionTimeframe);
        self::assertSame($cell->modernIdentity?->configHash, $probeDecision->lineage->configHash);
        (new Filesystem())->remove($probeRoot);
        $store = new InMemoryPaperExecutionStore();
        $store->seedSources($prefix);
        $store->bindDataset($cell, self::DATASET_ID, self::EVENTS_SHA256, self::SOURCE_BUILD_VERSION);
        $intents = new RecordingCanonicalPaperOrderIntents();
        $strategy = new TriggerOnlyCanonicalPreparation(
            $trigger->eventId,
            $this->canonicalPreparation($market, $clock, $runtimeFactory, $resolver),
        );
        $crash = new SecondCanonicalFakeEffectCrash();

        try {
            $coordinator = $this->coordinator(
                $store,
                $market,
                $runtimeFactory,
                $clock,
                $strategy,
                $intents,
                $crash,
            );
            $crash->arm();
            self::assertEquals($triggerTime, $trigger->receivedTimestamp);
            try {
                $coordinator->consumeAt(
                    $cell,
                    PaperProfileEligibility::REFERENCE_ONLY,
                    self::DATASET_ID,
                    count($prefix),
                    $trigger,
                );
                self::fail('The canonical post-dispatch crash point was not reached: ' . $strategy->diagnostic);
            } catch (\RuntimeException $exception) {
                self::assertSame('injected_after_canonical_fake_effect', $exception->getMessage());
            }

            self::assertSame(1, $strategy->delegateCalls);
            self::assertSame(1, $intents->reservations);
            self::assertSame(0, $intents->acknowledgements);

            $restartedMarket = new PaperMarketStateProjector(new PaperKlineProvider());
            $restartedFactory = new PaperFakeRuntimeFactory($root, $clock, 'representative-modern-replay-seed');
            $restarted = $this->coordinator(
                $store,
                $restartedMarket,
                $restartedFactory,
                $clock,
                new TriggerOnlyCanonicalPreparation(
                    $trigger->eventId,
                    $this->canonicalPreparation($restartedMarket, $clock, $restartedFactory, $resolver),
                ),
                $intents,
            );
            $restarted->consumeAt(
                $cell,
                PaperProfileEligibility::REFERENCE_ONLY,
                self::DATASET_ID,
                count($prefix),
                $trigger,
            );

            $entries = array_values(array_filter(
                $restartedFactory->forCell($cell)->stateStore->getOrders('BTCUSDT'),
                static fn ($order): bool => !$order->reduceOnly,
            ));
            self::assertCount(1, $entries);
            self::assertSame(1, $intents->reservations);
            self::assertSame(1, $intents->acknowledgements);
            self::assertSame([], $store->pendingEffects($cell));
            self::assertSame(count($prefix) + 1, $store->checkpoint($cell)->nextSourcePosition);
            self::assertSame('paper_canonical_fake_dispatcher', $entries[0]->metadata['canonical_dispatch_source'] ?? null);
            self::assertSame($cell->id, $entries[0]->metadata['paper_execution_cell_id'] ?? null);
            self::assertSame($cell->modernIdentity?->configHash, $entries[0]->metadata['config_hash'] ?? null);
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function publicVenueProvider(): iterable
    {
        yield 'OKX public mainnet' => [PaperMarketDataVenue::OKX->value];
        yield 'Hyperliquid public mainnet' => [PaperMarketDataVenue::HYPERLIQUID->value];
    }

    private function canonicalPreparation(
        PaperMarketStateProjector $market,
        PaperReplayClock $clock,
        PaperFakeRuntimeFactory $runtimeFactory,
        EffectiveTradingConfigResolver $resolver,
    ): PaperCanonicalStrategyPreparation {
        $books = new PaperCanonicalOrderBookSource($market, $clock);
        $provider = new PaperCanonicalStrategyEvidenceProvider(
            $resolver,
            new PaperCanonicalStrategyEvidenceSource(
                new PaperCanonicalIndicatorProjectionSource(
                    new PaperCanonicalIndicatorWindowSource($market, $clock),
                    new PaperCanonicalIndicatorDatasetBindingBuilder(),
                    new CanonicalIndicatorProjector(
                        new CanonicalPhpIndicatorCalculator(
                            new Rsi(),
                            new Macd(),
                            new Ema(),
                            new Adx(),
                            new Sma(),
                            new AtrCalculator(null),
                            new Vwap(),
                            new Bollinger(),
                        ),
                        new CanonicalFourHourAggregator(),
                    ),
                    $clock,
                ),
                new PaperCanonicalInstrumentSource($market, $clock),
                $books,
                new PaperCanonicalExecutionCostSource(
                    $books,
                    new PaperCanonicalFundingSource($market, $clock),
                    $clock,
                ),
                $runtimeFactory,
                new PaperCanonicalFakePortfolioSource($clock),
                new PaperCanonicalOrderPlanEvidenceSource($clock),
            ),
        );

        return new PaperCanonicalStrategyPreparation(
            new PaperCanonicalStrategyInputAssembler($provider),
            new PaperCanonicalStrategyRuntime(
                $resolver,
                self::getContainer()->get(CanonicalSetupRuleRuntime::class),
                new CanonicalExecutionPolicyCompiler(),
                $clock,
            ),
        );
    }

    private function coordinator(
        InMemoryPaperExecutionStore $store,
        PaperMarketStateProjector $market,
        PaperFakeRuntimeFactory $runtimeFactory,
        PaperReplayClock $clock,
        PaperCanonicalStrategyPreparationInterface $strategy,
        RecordingCanonicalPaperOrderIntents $intents,
        ?callable $crash = null,
    ): PaperExecutionCoordinator {
        return new PaperExecutionCoordinator(
            $store,
            $market,
            new NullPaperStrategyPreparation(),
            new PaperPreparedEffectCodec(),
            $runtimeFactory,
            self::getContainer()->get(PaperFakeEffectDispatcher::class),
            new RecordingProjectionStore(),
            new RecordingPaperOrderIntents(),
            new PaperRuntimeGuard(),
            new PaperDatabaseGuard(new class implements PaperDatabaseInspectorInterface {
                public function inspect(): PaperDatabaseInspection
                {
                    return new PaperDatabaseInspection('unit_paper_test', 0);
                }
            }),
            'test',
            true,
            $crash,
            canonicalStrategy: $strategy,
            canonicalCodec: new PaperCanonicalPreparedEffectCodec(),
            canonicalDispatcher: new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock),
            canonicalOrderIntents: $intents,
        );
    }

    /** @return array{list<PaperMarketEvent>, PaperMarketEvent} */
    private function representativeEvents(PaperMarketDataVenue $venue): array
    {
        $end = new \DateTimeImmutable('2026-08-20T12:00:00Z');
        $events = [];
        $sequence = 1;
        foreach ([
            [PaperMarketDataChannel::CANDLE_1H, 3600, 1000],
            [PaperMarketDataChannel::CANDLE_15M, 900, 250],
            [PaperMarketDataChannel::CANDLE_5M, 300, 250],
            [PaperMarketDataChannel::CANDLE_1M, 60, 250],
        ] as [$channel, $seconds, $count]) {
            $start = $end->modify(sprintf('-%d seconds', $seconds * $count));
            for ($index = 0; $index < $count; ++$index) {
                $openAt = $start->modify(sprintf('+%d seconds', $seconds * $index));
                $closeAt = $openAt->modify(sprintf('+%d seconds', $seconds));
                $last = $index === $count - 1;
                $receivedAt = $last && $channel === PaperMarketDataChannel::CANDLE_15M
                    ? $end->modify('+4 seconds')
                    : $closeAt;
                $events[] = $this->candle(
                    $venue,
                    $channel,
                    $openAt,
                    $receivedAt,
                    (string) $sequence++,
                    $index,
                    $count,
                );
            }
        }
        usort($events, static function (PaperMarketEvent $left, PaperMarketEvent $right): int {
            $byReceipt = $left->receivedTimestamp <=> $right->receivedTimestamp;
            if ($byReceipt !== 0) {
                return $byReceipt;
            }

            return $left->eventId <=> $right->eventId;
        });
        $triggerIndex = array_find_key(
            $events,
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::CANDLE_15M
                && $event->receivedTimestamp == $end->modify('+4 seconds'),
        );
        if (!is_int($triggerIndex)) {
            throw new \LogicException('representative_trigger_missing');
        }
        $trigger = $events[$triggerIndex];
        unset($events[$triggerIndex]);
        $events[] = $this->instrumentMetadata($venue, (string) $sequence++, $end->modify('+1 second'));
        $events[] = $this->funding($venue, (string) $sequence++, $end->modify('+2 seconds'));
        if ($venue === PaperMarketDataVenue::HYPERLIQUID) {
            $events[] = $this->snapshotBoundary((string) $sequence++, $end->modify('+2500 milliseconds'));
        }
        $events[] = $this->book($venue, (string) $sequence++, $end->modify('+3 seconds'));
        usort($events, static fn (PaperMarketEvent $left, PaperMarketEvent $right): int =>
            ($left->receivedTimestamp <=> $right->receivedTimestamp)
            ?: ($left->eventId <=> $right->eventId));

        return [array_values($events), $trigger];
    }

    private function candle(
        PaperMarketDataVenue $venue,
        PaperMarketDataChannel $channel,
        \DateTimeImmutable $openAt,
        \DateTimeImmutable $receivedAt,
        string $sequence,
        int $index,
        int $count,
    ): PaperMarketEvent {
        $timeframe = match ($channel) {
            PaperMarketDataChannel::CANDLE_1M => '1m',
            PaperMarketDataChannel::CANDLE_5M => '5m',
            PaperMarketDataChannel::CANDLE_15M => '15m',
            PaperMarketDataChannel::CANDLE_1H => '1h',
            default => throw new \LogicException('representative_timeframe_invalid'),
        };
        $phase = (($index - ($count - 1)) * M_PI / 4.0) + (M_PI / 4.0);
        $close = 30_000.0 + ($index * 0.8) + (30.0 * sin($phase));
        $tailIndex = $index - ($count - 20);
        if ($tailIndex >= 0 && $index < $count - 1) {
            $close -= ($tailIndex + 1) * 6.0;
        } elseif ($index === $count - 1) {
            $close += 90.0;
        }
        $open = $close - 1.0;
        $finalWick = $index === $count - 1 ? 300.0 : 50.0;
        $high = $close + $finalWick;
        $low = $close - $finalWick;
        $volume = $index === $count - 1 ? 1_000_000.0 : 10.0;

        $duration = match ($timeframe) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
        };
        $exchangeTimestamp = $venue === PaperMarketDataVenue::HYPERLIQUID
            ? $openAt->modify(sprintf('+%d seconds -1 millisecond', $duration))
            : $openAt;
        $payload = $venue === PaperMarketDataVenue::HYPERLIQUID
            ? [
                'native_symbol' => 'BTC',
                'interval' => $timeframe,
                'start_time' => $openAt->format('Uv'),
                'close_time' => $exchangeTimestamp->format('Uv'),
                'open' => number_format($open, 6, '.', ''),
                'high' => number_format($high, 6, '.', ''),
                'low' => number_format($low, 6, '.', ''),
                'close' => number_format($close, 6, '.', ''),
                'volume' => number_format($volume, 6, '.', ''),
                'trade_count' => '1',
                'confirmed' => true,
                'origin' => 'rest_candle_snapshot',
            ]
            : [
                'native_symbol' => 'BTC-USDT-SWAP',
                'bar' => $timeframe,
                'open' => number_format($open, 6, '.', ''),
                'high' => number_format($high, 6, '.', ''),
                'low' => number_format($low, 6, '.', ''),
                'close' => number_format($close, 6, '.', ''),
                'volume_contracts' => number_format($volume, 6, '.', ''),
                'volume_base' => number_format($volume, 6, '.', ''),
                'volume_quote' => number_format($volume * $close, 6, '.', ''),
                'confirmed' => true,
                'origin' => 'rest_history',
            ];

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            $venue,
            'BTCUSDT',
            $channel,
            $exchangeTimestamp,
            $receivedAt,
            $sequence,
            $payload,
        );
    }

    private function instrumentMetadata(
        PaperMarketDataVenue $venue,
        string $sequence,
        \DateTimeImmutable $receivedAt,
    ): PaperMarketEvent
    {
        $payload = $venue === PaperMarketDataVenue::HYPERLIQUID
            ? [
                'metadata_schema_version' => 'paper-instrument-metadata.v2',
                'native_symbol' => 'BTC',
                'instrument_type' => 'perpetual',
                'base_asset' => 'BTC',
                'quote_asset' => 'USDT',
                'settlement_asset' => 'USDC',
                'status' => 'live',
                'asset_id' => 0,
                'quantity_unit' => 'base_asset',
                'quantity_step' => '0.00001',
                'minimum_quantity' => '0.00001',
                'contract_value' => '1',
                'contract_multiplier' => '1',
                'contract_value_unit' => 'BTC',
                'size_decimals' => 5,
                'price_precision_digits' => 5,
                'price_max_decimals' => 1,
                'maximum_leverage' => '50',
                'maximum_market_notional' => '15000000',
                'maximum_limit_notional' => '150000000',
                'order_notional_limit_model' => 'hyperliquid-max-order-notional-by-leverage.v1',
                'source_epoch' => 1,
                'origin' => 'rest_meta',
            ]
            : [
                'metadata_schema_version' => 'paper-instrument-metadata.v2',
                'native_symbol' => 'BTC-USDT-SWAP',
                'instrument_type' => 'perpetual',
                'base_asset' => 'BTC',
                'quote_asset' => 'USDT',
                'settlement_asset' => 'USDT',
                'status' => 'live',
                'quantity_unit' => 'contracts',
                'quantity_step' => '0.1',
                'minimum_quantity' => '0.1',
                'maximum_market_quantity' => '1000',
                'maximum_limit_quantity' => '2000',
                'contract_value' => '0.01',
                'contract_multiplier' => '1',
                'contract_value_unit' => 'BTC',
                'price_tick' => '0.1',
                'maximum_leverage' => '100',
                'source_epoch' => 1,
                'origin' => 'rest_public_instruments',
            ];

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            $venue,
            'BTCUSDT',
            PaperMarketDataChannel::INSTRUMENT_METADATA,
            $receivedAt->modify('-1 second'),
            $receivedAt,
            $sequence,
            $payload,
        );
    }

    private function funding(
        PaperMarketDataVenue $venue,
        string $sequence,
        \DateTimeImmutable $receivedAt,
    ): PaperMarketEvent
    {
        $payload = $venue === PaperMarketDataVenue::HYPERLIQUID
            ? [
                'funding_schema_version' => 'paper-funding-rate.v2',
                'native_symbol' => 'BTC',
                'instrument_type' => 'perpetual',
                'funding_rate' => '0.00001',
                'observed_at_ms' => $receivedAt->format('Uv'),
                'funding_interval_seconds' => 3600,
                'method' => 'current_asset_context',
                'formula_type' => 'metaAndAssetCtxsFunding',
                'settlement_state' => 'processing',
                'source_epoch' => 1,
                'origin' => 'rest_public_meta_and_asset_contexts',
            ]
            : [
                'funding_schema_version' => 'paper-funding-rate.v1',
                'native_symbol' => 'BTC-USDT-SWAP',
                'instrument_type' => 'perpetual',
                'funding_rate' => '0.00001',
                'observed_at_ms' => $receivedAt->format('Uv'),
                'funding_time_ms' => $receivedAt->modify('+8 hours')->format('Uv'),
                'next_funding_time_ms' => $receivedAt->modify('+16 hours')->format('Uv'),
                'funding_interval_seconds' => 28800,
                'method' => 'current_period',
                'formula_type' => 'withRate',
                'settlement_state' => 'settled',
                'source_epoch' => 1,
                'origin' => 'rest_public_funding_rate',
            ];

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            $venue,
            'BTCUSDT',
            PaperMarketDataChannel::FUNDING_RATE,
            $receivedAt,
            $receivedAt,
            $sequence,
            $payload,
        );
    }

    private function snapshotBoundary(string $sequence, \DateTimeImmutable $receivedAt): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            $receivedAt,
            $receivedAt,
            $sequence,
            ['native_symbol' => 'BTC', 'reason' => 'initial', 'source_epoch' => 1],
        );
    }

    private function book(
        PaperMarketDataVenue $venue,
        string $sequence,
        \DateTimeImmutable $receivedAt,
    ): PaperMarketEvent
    {
        $exchangeTimestamp = $receivedAt->modify('-1 second');
        $payload = $venue === PaperMarketDataVenue::HYPERLIQUID
            ? [
                'native_symbol' => 'BTC',
                'bid_price' => '30310',
                'bid_size' => '5',
                'ask_price' => '30311',
                'ask_size' => '4',
                'bid_level_count' => '1',
                'ask_level_count' => '1',
                'source_time' => $exchangeTimestamp->format('Uv'),
                'source_epoch' => '1',
                'source_book_hash_nibbles' => array_fill(0, 64, 10),
                'origin' => 'ws_l2_book',
                'synthetic' => false,
            ]
            : [
                'native_symbol' => 'BTC-USDT-SWAP',
                'bid_price' => '30310.0',
                'bid_size_contracts' => '5',
                'bid_order_count' => '2',
                'ask_price' => '30311.0',
                'ask_size_contracts' => '4',
                'ask_order_count' => '3',
                'source_seq_id' => $sequence,
                'source_prev_seq_id' => null,
                'source_epoch' => 1,
                'origin' => 'ws_books',
            ];

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            $venue,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $exchangeTimestamp,
            $receivedAt,
            $sequence,
            $payload,
        );
    }

}
final class SecondCanonicalFakeEffectCrash
{
    private bool $armed = false;
    private int $afterFakeEffects = 0;

    public function arm(): void
    {
        $this->armed = true;
    }

    public function __invoke(PaperCrashPoint $point): void
    {
        if (!$this->armed || $point !== PaperCrashPoint::AFTER_FAKE_EFFECT) {
            return;
        }
        ++$this->afterFakeEffects;
        if ($this->afterFakeEffects === 2) {
            throw new \RuntimeException('injected_after_canonical_fake_effect');
        }
    }
}

final class TriggerOnlyCanonicalPreparation implements PaperCanonicalStrategyPreparationInterface
{
    public int $delegateCalls = 0;
    public string $diagnostic = 'not_called';

    public function __construct(
        private readonly string $triggerEventId,
        private readonly PaperCanonicalStrategyPreparationInterface $delegate,
    ) {
    }

    public function prepareFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        string $sourceDatasetId,
        string $sourceEventsFileSha256,
        string $sourceBuildVersion,
    ): ?\App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyDecision {
        if (!hash_equals($this->triggerEventId, $event->eventId)) {
            return null;
        }
        ++$this->delegateCalls;

        $decision = $this->delegate->prepareFor(
            $cell,
            $event,
            $sourceDatasetId,
            $sourceEventsFileSha256,
            $sourceBuildVersion,
        );
        $this->diagnostic = $decision === null ? 'no_decision' : 'planned';

        return $decision;
    }
}

final class NullPaperStrategyPreparation implements \App\Trading\Paper\Execution\Strategy\PaperStrategyPreparationInterface
{
    public function prepareFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
    ): ?\App\TradeEntry\Dto\PreparedTradeEntry {
        return null;
    }
}
