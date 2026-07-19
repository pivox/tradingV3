<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeOrderCreated;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FakePaperGoldenScenarioExecutionTest extends TestCase
{
    private const EXPECTED_FACTS = [
        'limit_maker_full_fill' => [
            'filled_quantity' => 1.0,
            'initial_order_status' => 'open',
            'matched_order_count' => 1,
            'open_protection_count' => 1,
            'order_status' => 'filled',
            'position_size' => 1.0,
        ],
        'limit_unfilled_then_expired' => [
            'open_order_count' => 0,
            'order_status' => 'expired',
            'reason' => 'immediate_execution_not_available',
        ],
        'partial_fill_then_cancel' => [
            'filled_quantity' => 0.4,
            'open_protection_count' => 1,
            'order_status' => 'cancelled',
            'position_size' => 0.4,
            'protection_quantity' => 0.4,
            'remaining_quantity' => 0.6,
        ],
        'fallback_taker' => [
            'fallback_client_linked' => true,
            'fallback_quantity' => 0.6,
            'fallback_status' => 'filled',
            'fallback_trigger' => 'end_of_zone',
            'fallback_type' => 'market',
            'maker_liquidity_role' => 'maker',
            'maker_slippage_cost_usdt' => 0.0,
            'metadata_redacted' => true,
            'parent_filled_quantity' => 0.4,
            'parent_remaining_quantity' => 0.6,
            'parent_status' => 'expired',
            'position_entry_order_count' => 2,
            'position_size' => 1.0,
            'protection_quantity' => 1.0,
            'slippage_guard_bps' => 25.240481,
            'taker_liquidity_role' => 'taker',
            'taker_slippage_bps' => 5.0,
            'taker_slippage_cost_usdt' => 7.50015,
        ],
        'market_with_slippage' => [
            'cost_model_version' => 'fixed_adverse_slippage_bps_v1',
            'execution_price' => 25000.5,
            'liquidity_role' => 'taker',
            'notional_usdt' => 25000.5,
            'slippage_bps' => 5.0,
            'slippage_cost_usdt' => 12.50025,
            'spread_cost_usdt' => 0.0,
            'spread_model_version' => 'top_of_book_embedded_spread_v1',
        ],
        'insufficient_balance' => [
            'accepted' => false,
            'available_margin_unchanged' => true,
            'event_count' => 1,
            'event_types' => ['order.rejected'],
            'exchange_order_id' => 'fake-000001',
            'open_order_count' => 0,
            'open_position_count' => 0,
            'order_count' => 1,
            'order_status' => 'rejected',
            'persisted_filled_quantity' => 0.0,
            'persisted_identity_matches_result' => true,
            'persisted_order_status' => 'rejected',
            'reason' => 'insufficient_balance',
            'rejection_event_count' => 1,
            'rejection_event_order_id' => 'fake-000001',
            'available_margin_before' => 100000.0,
            'persisted_quantity' => 13.0,
            'requested_initial_margin' => 108116.666667,
        ],
        'precision_reject' => [
            'accepted' => false,
            'available_margin_unchanged' => true,
            'event_count' => 1,
            'event_types' => ['order.rejected'],
            'exchange_order_id' => 'fake-000001',
            'open_order_count' => 0,
            'open_position_count' => 0,
            'order_count' => 1,
            'order_status' => 'rejected',
            'persisted_filled_quantity' => 0.0,
            'persisted_identity_matches_result' => true,
            'persisted_order_status' => 'rejected',
            'reason' => 'price_not_quantized',
            'rejection_event_count' => 1,
            'rejection_event_order_id' => 'fake-000001',
            'persisted_price' => 24950.01,
            'price_tick' => '0.10',
            'submitted_price' => 24950.01,
        ],
        'leverage_cap_reject' => [
            'accepted' => false,
            'available_margin_unchanged' => true,
            'event_count' => 1,
            'event_types' => ['order.rejected'],
            'exchange_order_id' => 'fake-000001',
            'open_order_count' => 0,
            'open_position_count' => 0,
            'order_count' => 1,
            'order_status' => 'rejected',
            'persisted_filled_quantity' => 0.0,
            'persisted_identity_matches_result' => true,
            'persisted_order_status' => 'rejected',
            'reason' => 'leverage_above_maximum',
            'rejection_event_count' => 1,
            'rejection_event_order_id' => 'fake-000001',
            'leverage_setting_count' => 0,
            'max_leverage' => 100,
            'persisted_leverage' => 101,
            'requested_leverage' => 101,
        ],
        'duplicate_client_order_id' => [
            'active_order_count' => 1,
            'idempotent_replay' => true,
            'same_exchange_order_id' => true,
        ],
        'timeout_after_acceptance' => [
            'error_code' => 'network_timeout',
            'event_count_unchanged' => true,
            'idempotent_replay' => true,
            'order_count_unchanged' => true,
            'outcome_unknown' => true,
            'open_protection_count' => 1,
            'protection_status' => 'accepted',
            'same_exchange_order_id' => true,
        ],
        'stop_loss_attach_success' => [
            'entry_status' => 'filled',
            'open_protection_count' => 1,
            'protection_reduce_only' => true,
            'protection_status' => 'accepted',
        ],
        'stop_loss_attach_failure' => [
            'compensation_order_status' => 'filled',
            'compensation_order_type' => 'market',
            'compensation_outcome' => 'position_closed',
            'compensation_reduce_only' => true,
            'compensation_status' => 'completed',
            'entry_status' => 'filled',
            'fail_safe_action' => 'reduce_only_market_close',
            'open_order_count' => 0,
            'open_position_count' => 0,
            'position_closed_count' => 1,
            'protection_status' => 'rejected',
        ],
        'tp1_then_trailing' => [
            'fixture_version' => 'fake-tp1-trailing-fixtures-v1',
            'long' => [
                'activation_stop' => 25100.0,
                'armed_event_count' => 1,
                'cost_completeness' => 'complete',
                'duplicate_price_idempotent' => true,
                'entry_quantity' => 1.0,
                'fill_count' => 3,
                'gap_fill_price' => 25250.0,
                'metadata_redacted' => true,
                'open_order_count' => 0,
                'open_position_count' => 0,
                'quantity_coherent' => true,
                'restart_restored' => true,
                'stop_monotone' => true,
                'terminal_replay_idempotent' => true,
                'tp1_quantity' => 0.4,
                'trailing_offset' => 100.0,
                'trailing_quantity' => 0.6,
                'triggered_event_count' => 1,
                'updated_event_count' => 2,
                'watermark_after_restart' => 25300.0,
            ],
            'short' => [
                'activation_stop' => 24900.0,
                'armed_event_count' => 1,
                'cost_completeness' => 'complete',
                'duplicate_price_idempotent' => true,
                'entry_quantity' => 1.0,
                'fill_count' => 3,
                'gap_fill_price' => 24750.0,
                'metadata_redacted' => true,
                'open_order_count' => 0,
                'open_position_count' => 0,
                'quantity_coherent' => true,
                'restart_restored' => true,
                'stop_monotone' => true,
                'terminal_replay_idempotent' => true,
                'tp1_quantity' => 0.4,
                'trailing_offset' => 100.0,
                'trailing_quantity' => 0.6,
                'triggered_event_count' => 1,
                'updated_event_count' => 2,
                'watermark_after_restart' => 24700.0,
            ],
        ],
        'gap_at_stop_loss' => [
            'fill_price' => 24790.0,
            'open_position_count' => 0,
            'order_status' => 'filled',
            'stop_price' => 24800.0,
        ],
        'websocket_disconnect_resync' => [
            'disconnect_code' => 'fake_private_ws_disconnected',
            'events_before_disconnect' => 2,
            'open_protection_count' => 2,
            'resumed_without_duplicate_or_loss' => true,
            'resync_required_before_reconnect' => true,
        ],
        'duplicate_out_of_order_event' => [
            'conflict_code' => 'fake_private_ws_sequence_conflict',
            'conflict_total' => 1,
            'duplicate_total' => 1,
            'gap_code' => 'fake_private_ws_sequence_gap',
            'gap_total' => 1,
            'no_projection_after_gap' => true,
            'normalized_projection_count' => 5,
            'normalized_projection_signatures' => [
                '564b0f3b7c5d79224fe9cc0401e23da782085129a8e14b31b9690abd2e5a9939',
                '2be15b8f66ebfe037608198007836f50acff77c672ea021450926dbb6bb5b103',
                '51c2ea450770798782291e7fc7392d41a7a34a1fa6f2e69e2af38d502c10207e',
                'a537ee643c8990986b8167ca3c7cdde2565a9b379b632b6252566c08b076b338',
                '7683f90540ac35361280ac64d4dd830afdee97be17851b60e05e7d74d999b36d',
            ],
            'normalized_projections_unique' => true,
            'resync_total' => 1,
            'restart_preserved_resync' => true,
            'resumed_contiguously' => true,
        ],
        'restart_with_open_position' => [
            'event_sequence_continued' => true,
            'historical_events_preserved' => true,
            'position_size' => 1.0,
            'protection_order_count' => 1,
        ],
        'funding' => [
            'absent_rate_status' => 'unknown',
            'event_deadlines' => [
                '2026-01-01T16:00:00+00:00',
                '2026-01-01T08:00:00+00:00',
            ],
            'fixture_version' => 'fake-funding-fixtures-v1',
            'funding_event_count' => 2,
            'internal_trade_id_preserved' => true,
            'known_currency' => 'USDT',
            'long_negative_amount' => '1.000000000000',
            'long_positive_amount' => '-2.000000000000',
            'model_version' => 'fake-funding-notional-rate-interval-v1',
            'no_position_status' => 'no_position',
            'normalized_fill_count' => 0,
            'normalized_funding_count' => 2,
            'out_of_order_amount' => '-2.000000000000',
            'partial_amount' => '-0.500000000000',
            'restart_replayed' => true,
            'same_deadline_replayed' => true,
            'short_negative_amount' => '-1.000000000000',
            'short_positive_amount' => '2.000000000000',
            'unknown_currency' => 'EUR',
            'unknown_currency_amount_usdt' => null,
            'unknown_currency_native_amount' => '-1.000000000000',
        ],
        'one_way_conflict' => [
            'active_order_conflict_source' => 'active_order',
            'active_order_rejected' => true,
            'available_margin_unchanged' => true,
            'conflict_event_count' => 1,
            'conflicting_position_side' => 'long',
            'exposure_unchanged' => true,
            'flat_allows_opposite' => true,
            'independent_symbols' => true,
            'long_blocks_short' => true,
            'metadata_redacted' => true,
            'position_mode_version' => 'fake-one-way-v1',
            'reason' => 'one_way_position_conflict',
            'reduce_only_exit_allowed' => true,
            'rejected_order_persisted' => true,
            'replay_idempotent' => true,
            'restart_enforced' => true,
            'same_rejected_order_id' => true,
            'short_blocks_long' => true,
        ],
        'dry_run_multi_profiles_same_symbol' => [
            'business_lock_contract_conflict_reason' => 'cross_profile_symbol_locked',
            'business_lock_contract_conflict_status' => 'blocked',
            'business_lock_evidence_status' => 'not_exercised',
            'business_lock_observed' => false,
            'business_lock_scope' => 'exchange+market_type+symbol',
            'config_hashes' => [
                'sha256:b0d91d352646330134a7c91d58d3ca21a7eb41e9828050f68d1f33eba8589567',
                'sha256:d7cbdfa876ad668c56b9662d01906d0ed925c39c43b5814ece9ca44d71a0e344',
                'sha256:01e5260242def9bd63f8e6b076bc88e99f74273c14e615e8cff828b5406d9a64',
            ],
            'config_hashes_distinct' => true,
            'disabled_sets' => ['recipe_fake_multi_disabled'],
            'dry_run_forced' => true,
            'exchange_call_proof' => [
                'bitmart' => 'fake_provider_boundary',
                'hyperliquid' => 'http_client_guard',
                'okx' => 'http_client_guard',
            ],
            'exchange_calls' => ['bitmart' => 0, 'hyperliquid' => 0, 'okx' => 0],
            'fixture_id' => 'fake-multi-profile-same-symbol-v1',
            'lineage_sets_distinct' => true,
            'metadata_redacted' => true,
            'orchestration_lock_keys' => [
                'regular|fake|perpetual|BTCUSDT',
                'scalper|fake|perpetual|BTCUSDT',
                'scalper_micro|fake|perpetual|BTCUSDT',
            ],
            'orchestration_lock_conflict_reason' => 'locked',
            'orchestration_lock_conflict_status' => 'skipped',
            'orchestration_lock_scope' => 'mtf_profile+exchange+market_type+symbol',
            'parallelism_bounded' => true,
            'profiles' => ['regular', 'scalper', 'scalper_micro'],
            'replay_idempotent' => true,
            'report_formats' => ['json', 'markdown'],
            'restart_replay_safe' => true,
            'set_ids' => [
                'recipe_fake_multi_regular',
                'recipe_fake_multi_scalper',
                'recipe_fake_multi_scalper_micro',
            ],
            'symbol' => 'BTCUSDT',
        ],
    ];

    public function testGoldenRunnerContractExists(): void
    {
        self::assertTrue(class_exists(FakePaperGoldenScenarioRunner::class));
    }

    public function testNormalizedProjectionSignaturesCoverTypedSemanticsAndCanonicalPayload(): void
    {
        self::assertTrue(class_exists(FakePaperGoldenScenarioRunner::class));
        $occurredAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $payload = [
            'event_sequence' => 1,
            'nested' => ['beta' => 2, 'alpha' => 1],
        ];
        $events = [
            new ExchangeOrderCreated($this->normalizedOrder(), $occurredAt, $payload),
            new ExchangeOrderCreated($this->normalizedOrder(exchangeOrderId: 'order-2'), $occurredAt, $payload),
            new ExchangeOrderCreated($this->normalizedOrder(quantity: 2.0), $occurredAt, $payload),
            new ExchangeOrderCreated($this->normalizedOrder(side: ExchangeOrderSide::SELL), $occurredAt, $payload),
            new ExchangeOrderCreated($this->normalizedOrder(status: ExchangeOrderStatus::FILLED), $occurredAt, $payload),
            new ExchangeOrderCreated($this->normalizedOrder(), $occurredAt, $payload + ['projection_marker' => 'changed']),
            new ExchangeFillReceived($this->normalizedFill('fill-1'), $payload),
            new ExchangeFillReceived($this->normalizedFill('fill-2'), $payload),
        ];

        $store = new GoldenPrivateWsProjectionStore();
        foreach ($events as $event) {
            $store->project($event);
        }

        self::assertCount(\count($events), array_unique($store->normalizedSignatures()));

        $firstOrdering = new GoldenPrivateWsProjectionStore();
        $firstOrdering->project(new ExchangeOrderCreated(
            $this->normalizedOrder(),
            $occurredAt,
            ['nested' => ['beta' => 2, 'alpha' => 1], 'event_sequence' => 1],
        ));
        $secondOrdering = new GoldenPrivateWsProjectionStore();
        $secondOrdering->project(new ExchangeOrderCreated(
            $this->normalizedOrder(),
            $occurredAt,
            ['event_sequence' => 1, 'nested' => ['alpha' => 1, 'beta' => 2]],
        ));

        self::assertSame(
            $firstOrdering->normalizedSignatures(),
            $secondOrdering->normalizedSignatures(),
            'Associative payload key order must not affect the canonical signature.',
        );
    }

    public function testRunnerKeysExactlyMatchExecutableCatalogRows(): void
    {
        self::assertTrue(method_exists(FakePaperGoldenScenarioRunner::class, 'keys'));

        $catalogKeys = array_values(array_map(
            static fn (array $scenario): string => $scenario['runner'],
            array_filter(
                self::catalog()['scenarios'],
                static fn (array $scenario): bool => $scenario['status'] === 'executable',
            ),
        ));

        self::assertCount(20, $catalogKeys);
        self::assertSame($catalogKeys, FakePaperGoldenScenarioRunner::keys());
    }

    /** @param array<string,mixed> $scenario */
    #[DataProvider('executableScenarioProvider')]
    public function testExecutableScenarioIsDeterministicAndMatchesGoldenFacts(array $scenario): void
    {
        self::assertTrue(method_exists(FakePaperGoldenScenarioRunner::class, 'run'));

        $first = (new FakePaperGoldenScenarioRunner())->run($scenario['runner']);
        $second = (new FakePaperGoldenScenarioRunner())->run($scenario['runner']);

        self::assertSame($first, $second);
        self::assertSame($scenario['name'], $first['scenario']);
        self::assertSame('pass', $first['outcome']);
        self::assertSame('2026-01-01T00:00:00+00:00', $first['clock']);
        self::assertSame(self::EXPECTED_FACTS[$scenario['name']], $first['facts']);
    }

    /** @return iterable<string,array{0:array<string,mixed>}> */
    public static function executableScenarioProvider(): iterable
    {
        foreach (self::catalog()['scenarios'] as $scenario) {
            if ($scenario['status'] === 'executable') {
                yield $scenario['name'] => [$scenario];
            }
        }
    }

    private function normalizedOrder(
        string $exchangeOrderId = 'order-1',
        float $quantity = 1.0,
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        ExchangeOrderStatus $status = ExchangeOrderStatus::OPEN,
    ): ExchangeOrderDto {
        $filledQuantity = $status === ExchangeOrderStatus::FILLED ? $quantity : 0.0;

        return new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $exchangeOrderId,
            clientOrderId: 'client-1',
            side: $side,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            status: $status,
            quantity: $quantity,
            filledQuantity: $filledQuantity,
            remainingQuantity: $quantity - $filledQuantity,
            price: 25000.0,
            averagePrice: $status === ExchangeOrderStatus::FILLED ? 25000.0 : null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            timeInForce: ExchangeTimeInForce::GTC,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: ['source' => 'canonical-signature-test'],
        );
    }

    private function normalizedFill(string $fillId): ExchangeFillDto
    {
        return new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'order-1',
            clientOrderId: 'client-1',
            fillId: $fillId,
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            quantity: 1.0,
            price: 25000.0,
            fee: 12.5,
            feeCurrency: 'USDT',
            filledAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: ['source' => 'canonical-signature-test'],
        );
    }

    /** @return array{schema_version:string,scenarios:list<array<string,mixed>>} */
    private static function catalog(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fake-paper/golden-scenarios-v1.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'The Fake/Paper golden catalog must be readable.');

        /** @var array{schema_version:string,scenarios:list<array<string,mixed>>} $catalog */
        $catalog = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        return $catalog;
    }
}
