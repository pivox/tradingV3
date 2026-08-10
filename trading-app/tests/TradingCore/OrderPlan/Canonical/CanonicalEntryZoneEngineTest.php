<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalMarketSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalPriceObservation;
use App\TradingCore\OrderPlan\Canonical\CanonicalTickSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalEntryZoneEngine::class)]
#[CoversClass(CanonicalEntryZoneRequest::class)]
final class CanonicalEntryZoneEngineTest extends TestCase
{
    private const NOW = '2026-08-10T12:00:00+00:00';

    public function testCalculatesLongZoneWithOutwardBoundsAndConservativeEntry(): void
    {
        $request = $this->request();
        $zone = $this->engine()->calculate($request);

        self::assertSame(99.4, $zone->lowerPrice);
        self::assertSame(100.4, $zone->upperPrice);
        self::assertSame(100.1, $zone->entryPrice);
        self::assertSame(0.1, $zone->tickSize);
        self::assertSame('long', $zone->side);
        self::assertSame('BTCUSDT', $zone->symbol);
        self::assertSame('vwap', $zone->anchorSource);
        self::assertSame('5m', $zone->anchorTimeframe);
        self::assertEquals(new \DateTimeImmutable('2026-08-10T11:59:45+00:00'), $zone->observedAt);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $zone->computedAt);
        self::assertEquals(new \DateTimeImmutable('2026-08-10T12:03:00+00:00'), $zone->expiresAt);
        self::assertSame($request->policy->configHash, $zone->configHash);
        self::assertCount(4, $zone->inputHashes);
    }

    public function testCalculatesShortAsymmetryAndQuantizesCandidateDown(): void
    {
        $zone = $this->engine()->calculate($this->request(
            side: 'short',
            anchorPrice: 100.0,
            atr: 0.75,
            candidatePrice: 100.39,
            tickSize: 0.1,
        ));

        self::assertSame(99.7, $zone->lowerPrice);
        self::assertSame(100.5, $zone->upperPrice);
        self::assertSame(100.3, $zone->entryPrice);
    }

    public function testRejectsStaleInput(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_input_stale');
        $this->engine()->calculate($this->request(observedAt: '2026-08-10T11:58:59+00:00'));
    }

    public function testRejectsFutureInput(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_input_future');
        $this->engine()->calculate($this->request(observedAt: '2026-08-10T12:00:01+00:00'));
    }

    public function testRejectsWrongAnchorInsteadOfFallingBack(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_anchor_mismatch');
        $this->engine()->calculate($this->request(anchorSource: 'sma21'));
    }

    public function testRejectsWrongAtrTimeframeInsteadOfFallingBack(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_atr_mismatch');
        $this->engine()->calculate($this->request(atrTimeframe: '15m'));
    }

    public function testRejectsCandidateOutsideZone(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_candidate_outside');
        $this->engine()->calculate($this->request(candidatePrice: 102.0));
    }

    public function testRejectsNonFiniteAtr(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_atr_invalid');
        $this->engine()->calculate($this->request(atr: NAN));
    }

    public function testRejectsInvalidTick(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_tick_invalid');
        $this->engine()->calculate($this->request(tickSize: 0.0));
    }

    public function testRejectsTickThatQuantizesLowerBoundToZero(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_bounds_invalid');
        $this->engine()->calculate($this->request(tickSize: 200.0));
    }

    public function testRejectsMarketIdentityMismatch(): void
    {
        $request = $this->request();

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_market_identity_mismatch');
        $this->engine()->calculate(new CanonicalEntryZoneRequest(
            $request->policy,
            $request->symbol,
            $request->anchor,
            $request->atr,
            new CanonicalMarketSnapshot('other', 'test', $request->symbol, 'perpetual', 'order_book', 100.1, $request->market->observedAt, $request->market->inputHash),
            $request->tick,
        ));
    }

    public function testRejectsMarketEnvironmentMismatch(): void
    {
        $request = $this->request();

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_market_identity_mismatch');
        $this->engine()->calculate(new CanonicalEntryZoneRequest(
            $request->policy,
            $request->symbol,
            $request->anchor,
            $request->atr,
            new CanonicalMarketSnapshot('fake', 'production', $request->symbol, 'perpetual', 'order_book', 100.1, $request->market->observedAt, $request->market->inputHash),
            $request->tick,
        ));
    }

    public function testRejectsSymbolOutsideEnvironmentScope(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_environment_scope_mismatch');
        $this->engine()->calculate($this->request(symbol: 'ETHUSDT'));
    }

    public function testRejectsMarketOutsideEnvironmentScope(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_environment_scope_mismatch');
        $this->engine()->calculate($this->request(marketType: 'spot'));
    }

    public function testRejectsAnchorFromDifferentMarketType(): void
    {
        $request = $this->request();

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_market_identity_mismatch');
        $this->engine()->calculate(new CanonicalEntryZoneRequest(
            $request->policy,
            $request->symbol,
            new CanonicalPriceObservation(
                'fake',
                'test',
                $request->symbol,
                'spot',
                'vwap',
                '5m',
                100.0,
                $request->anchor->observedAt,
                $request->anchor->inputHash,
            ),
            $request->atr,
            $request->market,
            $request->tick,
        ));
    }

    public function testRejectsTickFromDifferentMarketType(): void
    {
        $request = $this->request();

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_market_identity_mismatch');
        $this->engine()->calculate(new CanonicalEntryZoneRequest(
            $request->policy,
            $request->symbol,
            $request->anchor,
            $request->atr,
            $request->market,
            new CanonicalTickSnapshot(
                'fake',
                'test',
                $request->symbol,
                'spot',
                0.1,
                $request->tick->observedAt,
                $request->tick->inputHash,
            ),
        ));
    }

    private function engine(): CanonicalEntryZoneEngine
    {
        return new CanonicalEntryZoneEngine(new MockClock(self::NOW));
    }

    private function request(
        string $side = 'long',
        float $anchorPrice = 100.0,
        float $atr = 1.0,
        float $candidatePrice = 100.1,
        float $tickSize = 0.1,
        string $observedAt = '2026-08-10T11:59:30+00:00',
        string $anchorSource = 'vwap',
        string $atrTimeframe = '5m',
        string $symbol = 'BTCUSDT',
        string $marketType = 'perpetual',
    ): CanonicalEntryZoneRequest {
        $policy = CanonicalExecutionPolicyFixture::policy($side);
        $observed = new \DateTimeImmutable($observedAt);

        return new CanonicalEntryZoneRequest(
            policy: $policy,
            symbol: $symbol,
            anchor: new CanonicalPriceObservation('fake', 'test', $symbol, $marketType, $anchorSource, '5m', $anchorPrice, $observed, 'sha256:' . str_repeat('1', 64)),
            atr: new CanonicalPriceObservation('fake', 'test', $symbol, $marketType, 'atr', $atrTimeframe, $atr, $observed, 'sha256:' . str_repeat('2', 64)),
            market: new CanonicalMarketSnapshot('fake', 'test', $symbol, $marketType, 'order_book', $candidatePrice, new \DateTimeImmutable('2026-08-10T11:59:45+00:00'), 'sha256:' . str_repeat('3', 64)),
            tick: new CanonicalTickSnapshot('fake', 'test', $symbol, $marketType, $tickSize, $observed, 'sha256:' . str_repeat('4', 64)),
        );
    }
}
