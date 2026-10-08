<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Runtime;

use App\Tests\Trading\Paper\Execution\InMemoryPaperExecutionStore;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Configuration\PaperPrivateConfigurationReader;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use App\Trading\Paper\Runtime\PaperReplayCheckpointResolver;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use App\Trading\Paper\Runtime\PaperReplayStrategySelection;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** #132 decision a: every Paper execution cell replays candles at their close, its own trigger last. */
#[CoversClass(PaperReplayReadinessService::class)]
final class PaperReplayReadinessReplayOrderTest extends TestCase
{
    private string $root;
    private string $dataset;
    private string $configuration;

    protected function setUp(): void
    {
        $this->root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/paper_replay_order_' . bin2hex(random_bytes(5));
        $this->dataset = $this->root . '/dataset';
        mkdir($this->dataset, 0700, true);
        foreach (['manifest.json', 'events.ndjson'] as $file) {
            copy(__DIR__ . '/../../../Fixtures/PaperExecution/okx-mainnet-cell/' . $file, $this->dataset . '/' . $file);
            chmod($this->dataset . '/' . $file, 0600);
        }
        $this->configuration = $this->root . '/configuration.json';
        file_put_contents($this->configuration, '{"strategy":{"mode":"day_trading"}}');
        chmod($this->configuration, 0600);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataset . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataset);
        @unlink($this->configuration);
        @rmdir($this->root);
    }

    /** @return iterable<string, array{array{string|null, string|null, string|null, string|null, string|null, string|null}, PaperMarketDataChannel}> */
    public static function strategies(): iterable
    {
        // PaperMtfStrategyBridge evaluates a legacy cell on every confirmed 1m candle.
        yield 'legacy' => [['regular', null, null, null, null, null], PaperMarketDataChannel::CANDLE_1M];
        yield 'micro_scalping' => [[null, 'micro_scalping', '1.1.0', 'micro_scalping.momentum_ofi.long', '1.1.0', 'long'], PaperMarketDataChannel::CANDLE_1M];
        yield 'scalping' => [[null, 'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0', 'long'], PaperMarketDataChannel::CANDLE_5M];
        yield 'day_trading' => [[null, 'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0', 'long'], PaperMarketDataChannel::CANDLE_15M];
    }

    /** @param array{string|null, string|null, string|null, string|null, string|null, string|null} $options */
    #[DataProvider('strategies')]
    public function testEveryCellReplaysCandlesAtTheirCloseWithItsOwnTriggerLast(
        array $options,
        PaperMarketDataChannel $trigger,
    ): void {
        $preparation = $this->service()->prepare(
            $this->dataset,
            $this->configuration,
            PaperReplayStrategySelection::fromOptions(...$options),
            'paper-replay-order-001',
        );

        self::assertNotNull($preparation->replayOrder);
        self::assertTrue($preparation->replayOrder->candlesAtClose);
        self::assertSame($trigger, $preparation->replayOrder->triggerChannel);
    }

    private function service(): PaperReplayReadinessService
    {
        $verifier = new PaperDatasetVerifier();
        $clock = new PaperReplayClock();
        $store = new InMemoryPaperExecutionStore();

        return new PaperReplayReadinessService(
            $verifier,
            new PaperPrivateConfigurationReader(),
            new PaperConfigurationSnapshotFactory(),
            new PaperProfileRegistry(),
            $clock,
            new class implements PaperEventCoordinatorInterface {
                public function assertReady(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, array $symbols): void
                {
                }

                public function consumeAt(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, int $sourcePosition, PaperMarketEvent $event): void
                {
                    throw new \LogicException('not_called');
                }
            },
            new PaperReplayReader($verifier, new PaperReplayCheckpointStore(), $clock),
            $store,
            new PaperReplayCheckpointResolver($store),
            new EffectiveTradingConfigResolver(),
        );
    }
}
