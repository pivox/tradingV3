<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Identity;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperModernStrategyIdentity::class)]
final class PaperModernStrategyIdentityTest extends TestCase
{
    public function testExactExecutablePaperSnapshotCreatesIdentity(): void
    {
        $identity = PaperModernStrategyIdentity::fromResolvedSnapshot(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            self::snapshot(),
        );

        self::assertSame('day_trading', $identity->modeId);
        self::assertSame('1.1.0', $identity->modeVersion);
        self::assertSame('day_trading.trend_continuation.long', $identity->setupId);
        self::assertSame('1.1.0', $identity->setupVersion);
        self::assertSame('long', $identity->side);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $identity->configHash);
        self::assertSame('sha256:' . str_repeat('c', 64), $identity->conditionCatalogHash);
    }

    public function testVenueNetworkAndPaperCapabilityMustMatchExactly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_modern_identity_market_scope_mismatch');

        PaperModernStrategyIdentity::fromResolvedSnapshot(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            self::snapshot(),
        );
    }

    public function testNonPaperCapabilityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_modern_identity_capability_invalid');

        PaperModernStrategyIdentity::fromResolvedSnapshot(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            self::snapshot(ShadowExecutionCapability::Backtest),
        );
    }

    public function testBlockedSnapshotIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_modern_identity_snapshot_not_executable');

        PaperModernStrategyIdentity::fromResolvedSnapshot(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            self::snapshot(blockers: ['blocked_condition']),
        );
    }

    /** @param list<string> $blockers */
    private static function snapshot(
        ShadowExecutionCapability $capability = ShadowExecutionCapability::Paper,
        array $blockers = [],
    ): EffectiveTradingConfigSnapshot {
        $request = new EffectiveTradingConfigRequest(
            'day_trading',
            '1.1.0',
            'day_trading.trend_continuation.long',
            '1.1.0',
            'okx',
            'mainnet',
            'long',
            $capability,
        );
        $conditionHash = 'sha256:' . str_repeat('c', 64);
        $payload = ['decision' => ['enabled' => true]];
        $layers = [];
        foreach (['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'] as $type) {
            $layers[] = ['type' => $type, 'name' => $type, 'path' => $type . '.yaml', 'required' => true];
        }

        return new EffectiveTradingConfigSnapshot(
            $request,
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $conditionHash),
            $conditionHash,
            $layers,
            ['decision.enabled' => $layers[0]],
            $blockers === [],
            $blockers,
        );
    }
}
