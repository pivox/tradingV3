<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** #132 decision g: a Paper order executes on the Fake exchange; its lineage names the market-data venue. */
#[CoversClass(LineageContext::class)]
final class LineageContextExecutionBoundaryTest extends TestCase
{
    public function testAPaperLineageExecutesOnlyOnTheFakeExchange(): void
    {
        $paper = $this->lineage(ShadowExecutionCapability::Paper, 'okx', 'mainnet');

        $paper->assertExecutionBoundary('BTCUSDT', 'LONG', 'fake', 'perpetual');
        foreach (['okx', 'hyperliquid', 'okx'] as $exchange) {
            try {
                $paper->assertExecutionBoundary('BTCUSDT', 'LONG', $exchange, 'perpetual');
                self::fail('Paper execution accepted on ' . $exchange);
            } catch (LineageContextException $exception) {
                self::assertSame('canonical_identity_mismatch:exchange', $exception->getMessage());
            }
        }
        foreach ([['ETHUSDT', 'LONG', 'symbol'], ['BTCUSDT', 'SHORT', 'side']] as [$symbol, $side, $field]) {
            try {
                $paper->assertExecutionBoundary($symbol, $side, 'fake', 'perpetual');
                self::fail('Paper execution accepted another ' . $field);
            } catch (LineageContextException $exception) {
                self::assertSame('canonical_identity_mismatch:' . $field, $exception->getMessage());
            }
        }
    }

    public function testEveryOtherLineageKeepsTheExactTradeBoundary(): void
    {
        $fake = $this->lineage(ShadowExecutionCapability::Fake, 'fake', 'test');

        $fake->assertExecutionBoundary('BTCUSDT', 'LONG', 'fake', 'perpetual');
        foreach ([[$fake, 'okx'], [$fake, null]] as [$lineage, $exchange]) {
            $expected = null;
            try {
                $lineage->assertTradeBoundary('BTCUSDT', 'LONG', $exchange, 'perpetual');
            } catch (LineageContextException $exception) {
                $expected = $exception->getMessage();
            }
            $actual = null;
            try {
                $lineage->assertExecutionBoundary('BTCUSDT', 'LONG', $exchange, 'perpetual');
            } catch (LineageContextException $exception) {
                $actual = $exception->getMessage();
            }
            self::assertSame($expected, $actual, (string) $exchange);
        }
    }

    private function lineage(ShadowExecutionCapability $capability, string $exchange, string $environment): LineageContext
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0',
            $exchange, $environment, 'long', $capability,
        ));

        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-execution-boundary',
            'orchestration_set_id' => 'set-execution-boundary',
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'scalping.trend_continuation.long',
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => 'LONG',
            'exchange' => $exchange,
            'environment' => $environment,
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_key' => 'decision-execution-boundary',
            'decision_id' => 'ecdd472b-6a8b-589d-b725-130829338914',
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:execution-boundary',
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
    }
}
