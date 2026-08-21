<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeReservationDescriptor;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalFakeReservationDescriptor::class)]
final class PaperCanonicalFakeReservationDescriptorTest extends TestCase
{
    public function testEncodesAndRestoresTheExactCanonicalOpeningReservation(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $cell = $this->cell($effect);

        $descriptor = PaperCanonicalFakeReservationDescriptor::fromEffect($cell, $effect);
        $decoded = PaperCanonicalFakeReservationDescriptor::decode($descriptor->encoded());

        self::assertSame($descriptor->encoded(), $decoded->encoded());
        self::assertSame($cell->id, $decoded->cellId());
        self::assertSame($effect->decisionKey, $decoded->decisionKey());
        self::assertSame($effect->reservation->reservedRiskQuote, $decoded->reservedRiskQuote());
        self::assertSame($effect->reservation->reservedNotionalQuote, $decoded->reservedNotionalQuote());
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $decoded->identityHash());
        self::assertSame($decoded, $decoded->assertCell($cell));
        self::assertSame($decoded, $decoded->assertEffect($effect));
    }

    private function cell(PaperCanonicalPreparedEffect $effect): PaperExecutionCell
    {
        $provenance = $effect->provenance;
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
