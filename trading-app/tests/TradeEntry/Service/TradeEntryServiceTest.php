<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Service;

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
