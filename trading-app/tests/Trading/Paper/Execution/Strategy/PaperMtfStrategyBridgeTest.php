<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\Provider\Dto\KlineDto;
use App\Logging\Dto\LifecycleContextBuilder;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Strategy\PaperMtfStrategyBridge;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperMtfStrategyBridge::class)]
#[CoversClass(PaperPreparedEffectCodec::class)]
final class PaperMtfStrategyBridgeTest extends TestCase
{
    public function testClosedWarmOneMinuteCandleCallsMtfSynchronouslyWithFakeContext(): void
    {
        $validator = new RecordingValidator(['1m', '5m', '15m']);
        $provider = new PaperKlineProvider();
        foreach ([Timeframe::TF_1M, Timeframe::TF_5M, Timeframe::TF_15M] as $timeframe) {
            $provider->put($this->kline($timeframe));
        }
        $prepared = $this->prepared();
        $bridge = new PaperMtfStrategyBridge($validator, $provider, static fn (): PreparedTradeEntry => $prepared);

        self::assertSame($prepared, $bridge->prepareFor($this->cell(), $this->closedCandle()));
        self::assertCount(1, $validator->requests);
        self::assertSame(Exchange::FAKE, $validator->requests[0]->exchange);
        self::assertSame(MarketType::PERPETUAL, $validator->requests[0]->marketType);
        self::assertSame('scalper_micro', $validator->requests[0]->profile);
        self::assertSame('run-001', $validator->requests[0]->requestId);
        self::assertTrue($validator->requests[0]->dryRun);
    }

    public function testBridgeDoesNothingUntilAllTimeframesAreWarm(): void
    {
        $validator = new RecordingValidator(['1m', '5m']);
        $provider = new PaperKlineProvider();
        $provider->put($this->kline(Timeframe::TF_1M));
        $bridge = new PaperMtfStrategyBridge($validator, $provider, fn (): PreparedTradeEntry => $this->prepared());

        self::assertNull($bridge->prepareFor($this->cell(), $this->closedCandle()));
        self::assertSame([], $validator->requests);
    }

    public function testPreparedEffectCodecRoundTripsAndRejectsTampering(): void
    {
        $prepared = $this->prepared();
        $codec = new PaperPreparedEffectCodec();
        $encoded = $codec->encode($prepared, ['client_order_id' => 'paper-cid-1'], $this->cell()->provenance(\App\Trading\Paper\Execution\Profile\PaperProfileEligibility::REFERENCE_ONLY));
        $decoded = $codec->decode($encoded);
        self::assertSame($prepared->stablePlanPayload(), $decoded->prepared->stablePlanPayload());
        self::assertSame('paper-cid-1', $decoded->orderIntentIdentity['client_order_id']);

        $encoded['payload']['decision_key'] = 'tampered';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_prepared_effect_payload_invalid');
        $codec->decode($encoded);
    }

    private function prepared(): PreparedTradeEntry
    {
        return new PreparedTradeEntry(
            new OrderPlanModel('BTCUSDT', Side::Long, 'limit', 'isolated', 4, 100.0, 98.0, 104.0, 2, 3, 2, 1.0),
            null,
            'decision-1',
            'paper-trade-1',
            (new LifecycleContextBuilder('BTCUSDT'))->withDecisionKey('decision-1'),
            'scalper_micro',
            '1m',
        );
    }

    private function kline(Timeframe $timeframe): KlineDto
    {
        return new KlineDto('BTCUSDT', $timeframe, new \DateTimeImmutable('2026-08-01T10:00:00+00:00'), BigDecimal::of('100'), BigDecimal::of('101'), BigDecimal::of('99'), BigDecimal::of('100'), BigDecimal::of('5'), 'paper');
    }

    private function cell(): PaperExecutionCell
    {
        return PaperExecutionCell::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'sha256:' . str_repeat('a', 64), 'scalper_micro', 'run-001');
    }

    private function closedCandle(): PaperMarketEvent
    {
        return PaperMarketEvent::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'BTCUSDT', PaperMarketDataChannel::CANDLE_1M, new \DateTimeImmutable('2026-08-01T10:00:59+00:00'), new \DateTimeImmutable('2026-08-01T10:01:00+00:00'), '1', ['interval' => '1m', 'start_time' => '1785578400000', 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '5', 'confirmed' => true]);
    }
}

final class RecordingValidator implements MtfValidatorInterface
{
    /** @var list<MtfRunRequestDto> */
    public array $requests = [];

    /** @param list<string> $timeframes */
    public function __construct(private array $timeframes)
    {
    }

    public function run(MtfRunRequestDto $request): MtfRunResponseDto
    {
        $this->requests[] = $request;

        return new MtfRunResponseDto('run-001', 'success', 0.0, 1, 1, 1, 0, 0, 100.0, [], [], new \DateTimeImmutable('2026-08-01T10:01:00+00:00'));
    }

    public function getServiceName(): string
    {
        return 'recording';
    }

    /** @return list<string> */
    public function getListTimeframe(string $profile): array
    {
        return $this->timeframes;
    }
}
