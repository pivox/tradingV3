<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

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
        'restart_with_open_position' => [
            'event_sequence_continued' => true,
            'historical_events_preserved' => true,
            'position_size' => 1.0,
            'protection_order_count' => 1,
        ],
    ];

    public function testGoldenRunnerContractExists(): void
    {
        self::assertTrue(class_exists(FakePaperGoldenScenarioRunner::class));
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

        self::assertCount(16, $catalogKeys);
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
