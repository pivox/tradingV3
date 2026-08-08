<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Trading\Lineage\Export\CanonicalLifecycleExport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalLifecycleExport::class)]
final class CanonicalLifecycleExportTest extends TestCase
{
    public function testLegacyRowsRemainReadableWithoutInventedIdentity(): void
    {
        self::assertSame(
            ['lineage_classification' => 'legacy', 'identity' => null, 'reasons' => []],
            (new CanonicalLifecycleExport())->classify([['symbol' => 'BTCUSDT', 'config_profile' => 'scalper']]),
        );
    }

    public function testCompleteConvergentLifecycleExportsExactStructuredIdentity(): void
    {
        $entry = $this->event();
        $entry['position_id'] = null;
        $entry['trade_id'] = null;
        $close = $this->event();

        $result = (new CanonicalLifecycleExport())->classify([$entry, $close]);

        self::assertSame('canonical', $result['lineage_classification']);
        self::assertSame('scalping', $result['identity']['mode_id'] ?? null);
        self::assertSame('cfg-hash', $result['identity']['config_hash'] ?? null);
        self::assertSame('position-1', $result['identity']['position_id'] ?? null);
        self::assertSame('trade-1', $result['identity']['trade_id'] ?? null);
    }

    public function testPartialOrConflictingModernRowsFailClosed(): void
    {
        $partial = $this->event();
        $partial['condition_catalog_hash'] = null;
        $conflict = $this->event();
        $conflict['setup_id'] = 'scalping.breakout';

        $result = (new CanonicalLifecycleExport())->classify([$partial, $conflict]);

        self::assertSame('incomplete', $result['lineage_classification']);
        self::assertNull($result['identity']);
        self::assertContains('missing:condition_catalog_hash', $result['reasons']);
        self::assertContains('conflicting:setup_id', $result['reasons']);
    }

    public function testMultiSymbolRunIsClassifiedAsIndependentCanonicalLifecycles(): void
    {
        $btcEntry = $this->event();
        $btcEntry['position_id'] = null;
        $btcEntry['trade_id'] = null;
        $btcClose = $this->event();
        $ethEntry = $this->event('ETHUSDT', 'decision-2', 'intent-2', 'order-2', 'position-2', 'trade-2');
        $ethEntry['position_id'] = null;
        $ethEntry['trade_id'] = null;
        $ethClose = $this->event('ETHUSDT', 'decision-2', 'intent-2', 'order-2', 'position-2', 'trade-2');

        $results = (new CanonicalLifecycleExport())->classifyAll([$btcEntry, $ethEntry, $btcClose, $ethClose]);

        self::assertCount(2, $results);
        self::assertSame(['canonical', 'canonical'], array_column($results, 'lineage_classification'));
        self::assertSame(['BTCUSDT', 'ETHUSDT'], array_column(array_column($results, 'identity'), 'symbol'));
    }

    /** @return array<string,mixed> */
    private function event(
        string $symbol = 'BTCUSDT',
        string $decisionId = 'decision-1',
        string $intentId = 'intent-1',
        string $orderId = 'order-1',
        string $positionId = 'position-1',
        string $tradeId = 'trade-1',
    ): array
    {
        return [
            'run_id' => 'run-1', 'correlation_run_id' => 'correlation-1',
            'orchestration_run_id' => 'orchestration-run-1', 'orchestration_set_id' => 'set-1',
            'orchestration_dashboard_id' => 'dashboard-1', 'mode_id' => 'scalping', 'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback', 'setup_version' => '1.0.0', 'config_hash' => 'cfg-hash',
            'condition_catalog_hash' => 'catalog-hash', 'side' => 'LONG', 'exchange' => 'fake',
            'market_type' => 'perpetual', 'symbol' => $symbol, 'paper_network' => 'testnet',
            'market_data_venue' => 'fake', 'decision_id' => $decisionId, 'decision_key' => 'key-' . $decisionId,
            'intent_id' => $intentId, 'order_id' => $orderId, 'position_id' => $positionId, 'trade_id' => $tradeId,
        ];
    }
}
