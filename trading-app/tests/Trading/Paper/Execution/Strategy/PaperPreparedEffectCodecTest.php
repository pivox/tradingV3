<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Logging\Dto\LifecycleContextBuilder;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance;
use App\Trading\Paper\Execution\Persistence\PaperReplayBatchJournal;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** #132 decision h: a legacy pending effect is read back from jsonb with its keys shorter-first. */
#[CoversClass(PaperPreparedEffectCodec::class)]
#[CoversClass(PaperExecutionProvenance::class)]
final class PaperPreparedEffectCodecTest extends TestCase
{
    public function testAnEffectReadBackFromJsonbDecodesToTheSameDecision(): void
    {
        $codec = new PaperPreparedEffectCodec();
        $encoded = $codec->encode($this->prepared(), $this->identity(), $this->provenance());
        $stored = PaperReplayBatchJournal::asStoredJson($encoded);
        self::assertNotSame(array_keys($encoded['payload']), array_keys($stored['payload']));
        self::assertNotSame(array_keys($encoded['payload']['plan']), array_keys($stored['payload']['plan']));
        self::assertNotSame(
            array_keys($encoded['payload']['cell_provenance']),
            array_keys($stored['payload']['cell_provenance']),
        );

        $decoded = $codec->decode($stored);

        self::assertSame($this->provenance(), $decoded->provenance);
        self::assertSame($this->identity(), $decoded->orderIntentIdentity);
        self::assertSame(
            $encoded,
            $codec->encode($decoded->prepared, $decoded->orderIntentIdentity, $decoded->provenance),
        );
    }

    public function testTheKeySetStaysExact(): void
    {
        $codec = new PaperPreparedEffectCodec();
        $encoded = $codec->encode($this->prepared(), $this->identity(), $this->provenance());
        foreach ([
            'extra payload key' => static function (array &$payload): void {
                $payload['unexpected'] = true;
            },
            'missing plan key' => static function (array &$payload): void {
                unset($payload['plan']['stop_pivot']);
            },
            'extra provenance key' => static function (array &$payload): void {
                $payload['cell_provenance']['unexpected'] = 'value';
            },
            'missing provenance key' => static function (array &$payload): void {
                unset($payload['cell_provenance']['run_id']);
            },
        ] as $label => $mutation) {
            $case = $encoded;
            $mutation($case['payload']);
            $case['payload_checksum'] = hash('sha256', CanonicalJson::encode($case['payload']));
            try {
                $codec->decode(PaperReplayBatchJournal::asStoredJson($case));
                self::fail('Accepted: ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_prepared_effect_payload_invalid', $exception->getMessage(), $label);
            }
        }
    }

    private function prepared(): PreparedTradeEntry
    {
        return new PreparedTradeEntry(
            new OrderPlanModel(
                'BTCUSDT', Side::Long, 'limit', 'isolated', 1, 25000.0, 24800.0, 25200.0, 1, 3, 2, 1.0,
                entryZoneLow: 24990.0,
                entryZoneHigh: 25010.0,
                zoneExpiresAt: new \DateTimeImmutable('2026-08-20T12:03:00+00:00'),
                entryZoneMeta: ['model_version' => 'paper-test', 'source_event_id' => str_repeat('a', 64)],
                exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
            ),
            null,
            'decision-legacy-jsonb',
            'paper-trade-legacy-jsonb',
            new LifecycleContextBuilder('BTCUSDT'),
            'scalper_micro',
            '1m',
        );
    }

    /** @return array{client_order_id: string, order_intent_id: int} */
    private function identity(): array
    {
        return ['client_order_id' => 'paper-legacy-jsonb-1', 'order_intent_id' => 7];
    }

    /** @return array<string, string> */
    private function provenance(): array
    {
        $cell = PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('c', 64),
            'scalper_micro',
            'paper-legacy-jsonb-run',
        );

        return PaperExecutionProvenance::validate([
            'paper_network' => 'mainnet',
            'market_data_venue' => 'hyperliquid',
            'paper_execution_cell_id' => $cell->id,
            'configuration_snapshot_id' => 'sha256:' . str_repeat('c', 64),
            'paper_eligibility' => PaperProfileEligibility::REFERENCE_ONLY->value,
            'strategy_profile' => 'scalper_micro',
            'run_id' => 'paper-legacy-jsonb-run',
            'exchange' => 'fake',
        ]);
    }
}
