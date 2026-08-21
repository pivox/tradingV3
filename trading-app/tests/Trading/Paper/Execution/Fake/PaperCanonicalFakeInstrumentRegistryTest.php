<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeInstrumentDescriptor;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeInstrumentRegistry;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalFakeInstrumentRegistry::class)]
final class PaperCanonicalFakeInstrumentRegistryTest extends TestCase
{
    public function testBindsIdempotentlyAndReplacesOnlyWithoutActiveCanonicalState(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $cell = $this->cell($effect->provenance);
        $registry = new PaperCanonicalFakeInstrumentRegistry($cell, new FakeExchangeStateStore());

        self::assertNull($registry->find('BTCUSDT'));
        $first = $registry->bind($effect->plan);
        self::assertSame('1', $registry->find('BTCUSDT')?->contractSize);
        self::assertSame($first, $registry->bind($effect->plan));

        $replacement = PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01);
        self::assertNotSame($first, $registry->bind($replacement->plan));
        self::assertSame('0.01', $registry->find('BTCUSDT')?->contractSize);
    }

    public function testRestoresTheExactDescriptorFromAnActiveCanonicalOrder(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01);
        $cell = $this->cell($effect->provenance);
        $state = new FakeExchangeStateStore();
        $descriptor = PaperCanonicalFakeInstrumentDescriptor::fromPlan($cell, $effect->plan);
        $state->saveOrder($this->activeOrder($descriptor->encoded()));

        $restored = new PaperCanonicalFakeInstrumentRegistry($cell, $state);

        self::assertSame('0.01', $restored->find('BTCUSDT')?->contractSize);
        self::assertSame($descriptor->encoded(), $restored->bind($effect->plan));
    }

    public function testRejectsMissingOrForgedDescriptorOnActiveCanonicalState(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $cell = $this->cell($effect->provenance);

        foreach ([null, '{"forged":true}'] as $encoded) {
            $state = new FakeExchangeStateStore();
            $state->saveOrder($this->activeOrder($encoded));
            try {
                new PaperCanonicalFakeInstrumentRegistry($cell, $state);
                self::fail('Invalid canonical instrument state was restored.');
            } catch (\LogicException $exception) {
                self::assertSame('paper_canonical_fake_instrument_state_invalid', $exception->getMessage());
            }
        }
    }

    public function testRejectsInstrumentDriftWhileCanonicalStateIsActive(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $cell = $this->cell($effect->provenance);
        $state = new FakeExchangeStateStore();
        $registry = new PaperCanonicalFakeInstrumentRegistry($cell, $state);
        $descriptor = $registry->bind($effect->plan);
        $state->saveOrder($this->activeOrder($descriptor));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_fake_instrument_drift');
        $registry->bind(PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01)->plan);
    }

    private function activeOrder(?string $descriptor): ExchangeOrderDto
    {
        $metadata = ['canonical_dispatch_source' => 'paper_canonical_fake_dispatcher'];
        if ($descriptor !== null) {
            $metadata[PaperCanonicalFakeInstrumentDescriptor::METADATA_KEY] = $descriptor;
        }

        return new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'fake-000001',
            clientOrderId: 'paper-canonical-test',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            status: ExchangeOrderStatus::OPEN,
            quantity: 1.0,
            filledQuantity: 0.0,
            remainingQuantity: 1.0,
            price: 100.0,
            averagePrice: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            timeInForce: ExchangeTimeInForce::GTC,
            createdAt: new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            metadata: $metadata,
        );
    }

    /** @param array<string, string> $provenance */
    private function cell(array $provenance): PaperExecutionCell
    {
        $network = PaperMarketDataNetwork::from($provenance['paper_network']);
        $venue = PaperMarketDataVenue::from($provenance['market_data_venue']);

        return PaperExecutionCell::createModern(
            $network,
            $venue,
            $provenance['configuration_snapshot_id'],
            PaperModernStrategyIdentity::fromDurableIdentity(
                $network,
                $venue,
                $provenance['mode_id'],
                $provenance['mode_version'],
                $provenance['setup_id'],
                $provenance['setup_version'],
                $provenance['side'],
                $provenance['config_hash'],
                $provenance['condition_catalog_hash'],
            ),
            $provenance['run_id'],
        );
    }
}
