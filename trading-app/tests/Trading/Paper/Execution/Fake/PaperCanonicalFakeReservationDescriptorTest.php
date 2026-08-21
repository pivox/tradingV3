<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeReservationDescriptor;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\MarketData\CanonicalJson;
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

    public function testRejectsForgedAndNonCanonicalWireShapes(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $encoded = PaperCanonicalFakeReservationDescriptor::fromEffect($this->cell($effect), $effect)->encoded();
        $variants = [
            $this->mutate($encoded, static function (array &$document): void {
                $document['reserved_risk_quote'] = '999';
            }),
            $this->mutate($encoded, static function (array &$document): void {
                $document['reserved_risk_quote'] = '01.0';
            }, rehash: true),
            $this->mutate($encoded, static function (array &$document): void {
                unset($document['plan_hash']);
            }, rehash: true),
            $this->mutate($encoded, static function (array &$document): void {
                $document['unexpected'] = 'field';
            }, rehash: true),
        ];

        foreach ($variants as $variant) {
            try {
                PaperCanonicalFakeReservationDescriptor::decode($variant);
                self::fail('An invalid canonical reservation descriptor was accepted.');
            } catch (\LogicException $exception) {
                self::assertSame('paper_canonical_fake_reservation_descriptor_invalid', $exception->getMessage());
            }
        }
    }

    public function testRejectsLegacyAndForeignCells(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $cell = $this->cell($effect);
        $descriptor = PaperCanonicalFakeReservationDescriptor::fromEffect($cell, $effect);
        $legacy = PaperExecutionCell::create(
            $cell->network,
            $cell->marketDataVenue,
            $cell->configurationSnapshotId,
            'scalper',
            'legacy-reservation-run',
        );

        try {
            $descriptor->assertCell($legacy);
            self::fail('A legacy cell was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_fake_reservation_descriptor_invalid', $exception->getMessage());
        }

        $foreign = PaperExecutionCell::createModern(
            $cell->network,
            $cell->marketDataVenue,
            $cell->configurationSnapshotId,
            $cell->modernIdentity ?? throw new \LogicException('fixture_identity_missing'),
            'foreign-reservation-run',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_fake_reservation_descriptor_invalid');
        $descriptor->assertCell($foreign);
    }

    public function testEffectAssertionRejectsForeignPrivateScope(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $cell = $this->cell($effect);
        $foreignDigest = str_repeat('a', 64);
        $encoded = PaperCanonicalFakeReservationDescriptor::fromEffect($cell, $effect)->encoded();
        $foreign = $this->mutate($encoded, static function (array &$document) use ($foreignDigest): void {
            $document['paper_cell_id'] = 'sha256:' . $foreignDigest;
            $document['account_namespace'] = 'paper:cell:v2:' . $foreignDigest;
        }, rehash: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_fake_reservation_descriptor_invalid');
        PaperCanonicalFakeReservationDescriptor::decode($foreign)->assertEffect($effect);
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

    /**
     * @param callable(array<string, mixed>&):void $mutator
     */
    private function mutate(string $encoded, callable $mutator, bool $rehash = false): string
    {
        $document = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $mutator($document);
        if ($rehash) {
            unset($document['descriptor_hash']);
            $document['descriptor_hash'] = 'sha256:' . hash('sha256', CanonicalJson::encode($document));
        }

        return CanonicalJson::encode($document);
    }
}
