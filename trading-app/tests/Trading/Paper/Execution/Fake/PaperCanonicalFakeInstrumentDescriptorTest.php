<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeInstrumentDescriptor;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalFakeInstrumentDescriptor::class)]
final class PaperCanonicalFakeInstrumentDescriptorTest extends TestCase
{
    public function testBuildsAnExactDeterministicFakeInstrumentFromTheCanonicalPlan(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01);
        $descriptor = PaperCanonicalFakeInstrumentDescriptor::fromPlan(
            $this->cell($effect->provenance),
            $effect->plan,
        );

        self::assertSame('0.01', $descriptor->instrument()->contractSize);
        self::assertSame('0.1', $descriptor->instrument()->priceTick);
        self::assertSame('0.001', $descriptor->instrument()->quantityStep);
        self::assertSame(
            $descriptor->encoded(),
            PaperCanonicalFakeInstrumentDescriptor::decode($descriptor->encoded())->encoded(),
        );
    }

    public function testRejectsDescriptorHashDrift(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01);
        $descriptor = PaperCanonicalFakeInstrumentDescriptor::fromPlan(
            $this->cell($effect->provenance),
            $effect->plan,
        );
        $payload = json_decode($descriptor->encoded(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload['contract_size'] = '1';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_fake_instrument_descriptor_invalid');
        PaperCanonicalFakeInstrumentDescriptor::decode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testRejectsAPlanBoundToAnotherPaperCell(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $provenance = $effect->provenance;
        $provenance['paper_network'] = PaperMarketDataNetwork::MAINNET->value;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_fake_instrument_descriptor_invalid');
        PaperCanonicalFakeInstrumentDescriptor::fromPlan($this->cell($provenance), $effect->plan);
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
