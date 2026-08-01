<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Trading\Lineage\CanonicalTradeEntryConfigFactory;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\TradeEntry\EntryZone\EntryZoneCalculator;
use App\Config\TradeEntryConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalTradeEntryConfigFactory::class)]
final class CanonicalTradeEntryConfigFactoryTest extends TestCase
{
    public function testMapsOnlyOwnedCanonicalPathsFromAFull133Snapshot(): void
    {
        $config = CanonicalSnapshotFixture::config();
        $view = CanonicalTradeEntryConfigFactory::fromLineage(CanonicalSnapshotFixture::lineage($config));

        self::assertSame(50.0, $view->getDefault('initial_margin_usdt'));
        self::assertArrayNotHasKey('risk_pct_percent', $view->getDefaults());
        self::assertSame('limit', $view->getDefault('order_type'));
        self::assertSame('isolated', $view->getDefault('open_type'));
        self::assertSame(3.0, $view->getLeverage()['canonical_cap']);
        self::assertSame([
            'daily_loss_cap' => ['absolute_quote' => 20.0, 'percent_equity' => 2.0, 'quote_currency' => 'USDT'],
            'max_concurrent_positions' => 3,
            'mode_exposure_cap' => 10.0,
            'minimum_net_r' => 1.3,
        ], $view->getRisk());
        self::assertFalse($view->getFallbackEndOfZoneConfig()->enabled);
        self::assertSame(0.0002, $view->getFees()['maker_rate']);
        self::assertSame('vwap', $view->getEntry()['entry_zone']['from']);
        self::assertArrayNotHasKey('trade_entry', $view->getConfig());
    }

    public function testRejectsUnresolvedOwnedValueWithoutLegacyFallback(): void
    {
        $config = CanonicalSnapshotFixture::config();
        $config['mode']['leverage'] = ['state' => 'unresolved', 'value' => null, 'unit' => 'leverage_multiple'];

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_config_unresolved:mode.leverage');
        CanonicalTradeEntryConfigFactory::fromLineage(CanonicalSnapshotFixture::lineage($config));
    }

    public function testRejectsPercentOrIncompatibleTradeBudgetInsteadOfInventingMargin(): void
    {
        foreach ([
            ['state' => 'defined', 'value' => 0.5, 'unit' => 'percent_equity_per_trade'],
            ['state' => 'unresolved', 'value' => null, 'unit' => 'quote_notional'],
            ['state' => 'defined', 'value' => ['amount' => 50.0, 'quote_currency' => 'EUR'], 'unit' => 'quote_notional'],
        ] as $decision) {
            $config = CanonicalSnapshotFixture::config();
            $config['mode']['risk']['trade_budget'] = $decision;
            try {
                CanonicalTradeEntryConfigFactory::fromLineage(CanonicalSnapshotFixture::lineage($config));
                self::fail('An unresolved or incompatible canonical trade budget was accepted.');
            } catch (LineageContextException $exception) {
                self::assertSame('canonical_config_unresolved_or_unit_mismatch:mode.risk.trade_budget', $exception->getMessage());
            }
        }
    }

    public function testRejectsFictionalTradeEntryRoot(): void
    {
        $config = CanonicalSnapshotFixture::config();
        $config['trade_entry'] = ['defaults' => []];
        $this->expectExceptionMessage('canonical_config_invalid:roots');
        CanonicalTradeEntryConfigFactory::fromLineage(CanonicalSnapshotFixture::lineage($config));
    }

    public function testExplicitLegacyContextKeepsLegacyEntryZoneConfigPath(): void
    {
        $calculator = new EntryZoneCalculator(defaultConfig: new TradeEntryConfig(config: [
            'entry' => ['entry_zone' => ['from' => 'vwap', 'k_atr' => 0.4]],
        ]));

        $zone = $calculator->compute('BTCUSDT', lineageContext: LineageContext::legacy('BTCUSDT', 'bitmart', 'perpetual'));

        self::assertSame('open zone (no indicators)', $zone->rationale);
    }
}
