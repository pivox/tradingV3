<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Service;

use App\TradeEntry\Dto\PreflightReport;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Service\TradeEntryService;
use App\TradeEntry\Types\Side;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TradeEntryService::class)]
final class TradeEntryServiceTest extends TestCase
{
    public function testEnrichZoneDeviationPayloadAddsMissingValues(): void
    {
        $service = $this->instantiateServiceWithoutConstructor();
        $payload = [
            'candidate' => 1.02,
            'zone_min' => 1.0,
            'zone_max' => 1.01,
        ];
        $request = new TradeEntryRequest(
            symbol: 'GLMUSDT',
            side: Side::Long,
            zoneMaxDeviationPct: 0.02,
        );

        $result = $this->invokeEnrichZoneDeviationPayload($service, $payload, $request);

        self::assertArrayHasKey('zone_dev_pct', $result);
        self::assertArrayHasKey('zone_max_dev_pct', $result);
        self::assertSame(0.009804, $result['zone_dev_pct']);
        self::assertSame(0.02, $result['zone_max_dev_pct']);
    }

    public function testBuildZoneSkipEventDtoComputesVwapDistancePctWhenPivotIsVwap(): void
    {
        $service = $this->instantiateServiceWithoutConstructor();
        $request = new TradeEntryRequest(
            symbol: 'ZECUSDT',
            side: Side::Long,
            executionTf: '1m',
            atrValue: 0.5,
        );
        $preflight = new PreflightReport(
            symbol: 'ZECUSDT',
            bestBid: 99.9,
            bestAsk: 100.1,
            pricePrecision: 2,
            contractSize: 1.0,
            minVolume: 1,
            maxLeverage: 50,
            minLeverage: 1,
            availableUsdt: 1000.0,
            spreadPct: 0.002,
            volumeRatio: 1.2,
        );

        $context = [
            'zone_min' => 99.0,
            'zone_max' => 99.5,
            'candidate' => 100.0,
            'zone_dev_pct' => 0.01,
            'zone_max_dev_pct' => 0.02,
            'pivot_source' => 'vwap',
            'pivot' => 99.0,
        ];

        $method = new \ReflectionMethod(TradeEntryService::class, 'buildZoneSkipEventDto');
        $method->setAccessible(true);

        $dto = $method->invoke(
            $service,
            $request,
            $preflight,
            'decision-key',
            'scalper_micro',
            $context,
            null,
        );

        self::assertNotNull($dto);
        self::assertNotNull($dto->vwapDistancePct);
        self::assertEqualsWithDelta(0.01, $dto->vwapDistancePct, 1e-9);
    }

    private function instantiateServiceWithoutConstructor(): TradeEntryService
    {
        $ref = new \ReflectionClass(TradeEntryService::class);

        /** @var TradeEntryService $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        return $instance;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function invokeEnrichZoneDeviationPayload(
        TradeEntryService $service,
        array $payload,
        TradeEntryRequest $request
    ): array {
        $method = new \ReflectionMethod(TradeEntryService::class, 'enrichZoneDeviationPayload');
        $method->setAccessible(true);

        /** @var array<string,mixed> $result */
        $result = $method->invoke($service, $payload, $request);

        return $result;
    }
}
