<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FakePaperGoldenScenarioCatalogTest extends TestCase
{
    private const EXPECTED_CLASSIFICATIONS = [
        'limit_maker_full_fill' => ['executable', []],
        'limit_unfilled_then_expired' => ['executable', []],
        'partial_fill_then_cancel' => ['executable', []],
        'fallback_taker' => ['unsupported', ['fallback_taker_not_implemented']],
        'market_with_slippage' => ['executable', []],
        'insufficient_balance' => ['executable', []],
        'precision_reject' => ['executable', []],
        'leverage_cap_reject' => ['executable', []],
        'duplicate_client_order_id' => ['executable', []],
        'timeout_after_acceptance' => ['executable', []],
        'stop_loss_attach_success' => ['executable', []],
        'stop_loss_attach_failure' => ['partial', ['stop_attach_failure_compensation_not_integrated']],
        'tp1_then_trailing' => ['partial', ['trailing_stop_not_implemented']],
        'gap_at_stop_loss' => ['executable', []],
        'websocket_disconnect_resync' => ['executable', []],
        'duplicate_out_of_order_event' => ['partial', ['out_of_order_event_injection_not_implemented']],
        'restart_with_open_position' => ['executable', []],
        'funding' => ['unsupported', ['funding_model_not_implemented']],
        'one_way_conflict' => ['unsupported', ['one_way_conflict_guard_not_implemented']],
        'dry_run_multi_profiles_same_symbol' => ['partial', ['multi_profile_fake_recipe_not_consolidated']],
    ];

    private const EXPECTED_NAMES = [
        'limit_maker_full_fill',
        'limit_unfilled_then_expired',
        'partial_fill_then_cancel',
        'fallback_taker',
        'market_with_slippage',
        'insufficient_balance',
        'precision_reject',
        'leverage_cap_reject',
        'duplicate_client_order_id',
        'timeout_after_acceptance',
        'stop_loss_attach_success',
        'stop_loss_attach_failure',
        'tp1_then_trailing',
        'gap_at_stop_loss',
        'websocket_disconnect_resync',
        'duplicate_out_of_order_event',
        'restart_with_open_position',
        'funding',
        'one_way_conflict',
        'dry_run_multi_profiles_same_symbol',
    ];

    public function testCatalogContainsTheTwentyMandatoryScenariosInCanonicalOrder(): void
    {
        $catalog = self::catalog();

        self::assertSame('fake-paper-golden-v1', $catalog['schema_version']);
        self::assertSame(range(1, 20), array_column($catalog['scenarios'], 'id'));
        self::assertSame(self::EXPECTED_NAMES, array_column($catalog['scenarios'], 'name'));
    }

    public function testEveryScenarioIsEitherExecutableOrAnExplicitGap(): void
    {
        foreach (self::catalog()['scenarios'] as $scenario) {
            $fields = array_keys($scenario);
            sort($fields);
            self::assertSame(
                ['evidence', 'gap_codes', 'id', 'name', 'requirement', 'runner', 'status'],
                $fields,
                sprintf('Scenario %s must use the canonical schema.', $scenario['name']),
            );
            self::assertNotSame('', $scenario['requirement']);
            self::assertNotEmpty($scenario['evidence']);
            self::assertSame(
                self::EXPECTED_CLASSIFICATIONS[$scenario['name']],
                [$scenario['status'], $scenario['gap_codes']],
                sprintf('Scenario %s classification and gap codes are stable.', $scenario['name']),
            );

            if ($scenario['status'] === 'executable') {
                self::assertIsString($scenario['runner']);
                self::assertNotSame('', $scenario['runner']);
                self::assertSame([], $scenario['gap_codes']);

                continue;
            }

            self::assertContains($scenario['status'], ['partial', 'unsupported']);
            self::assertNull($scenario['runner']);
            self::assertNotEmpty($scenario['gap_codes']);
        }
    }

    public function testEveryEvidenceReferenceResolvesToAnExistingFileAndMethod(): void
    {
        $repositoryRoot = dirname(__DIR__, 4);

        foreach (self::catalog()['scenarios'] as $scenario) {
            foreach ($scenario['evidence'] as $reference) {
                self::assertIsString($reference);
                self::assertNotSame('', $reference);

                [$pathAndAnchor, $method] = array_pad(explode('::', $reference, 2), 2, null);
                [$path] = explode('#', $pathAndAnchor, 2);
                $absolutePath = str_starts_with($path, 'docs/')
                    ? $repositoryRoot . '/' . $path
                    : $repositoryRoot . '/trading-app/' . $path;

                self::assertFileExists($absolutePath, sprintf('Missing evidence %s.', $reference));
                if ($method !== null) {
                    $contents = file_get_contents($absolutePath);
                    self::assertIsString($contents);
                    self::assertStringContainsString('function ' . $method . '(', $contents);
                }
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
