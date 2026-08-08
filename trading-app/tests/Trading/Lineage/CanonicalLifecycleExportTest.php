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

    /** @return array<string,mixed> */
    private function event(): array
    {
        return [
            'run_id' => 'run-1', 'correlation_run_id' => 'correlation-1',
            'orchestration_run_id' => 'orchestration-run-1', 'orchestration_set_id' => 'set-1',
            'orchestration_dashboard_id' => 'dashboard-1', 'mode_id' => 'scalping', 'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback', 'setup_version' => '1.0.0', 'config_hash' => 'cfg-hash',
            'condition_catalog_hash' => 'catalog-hash', 'side' => 'LONG', 'exchange' => 'fake',
            'market_type' => 'perpetual', 'symbol' => 'BTCUSDT', 'paper_network' => 'testnet',
            'market_data_venue' => 'fake', 'decision_id' => 'decision-1', 'decision_key' => 'key-1',
            'intent_id' => 'intent-1', 'order_id' => 'order-1', 'position_id' => 'position-1', 'trade_id' => 'trade-1',
        ];
    }
}
