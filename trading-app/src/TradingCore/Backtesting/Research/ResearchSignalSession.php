<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Indicator\CanonicalPhpIndicatorCalculator;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Setup\SetupContractLoader;

/** Local candle research only; owns no transport, persistence or execution gateway. */
final class ResearchSignalSession
{
    public const FROZEN_END_MS = 1791525600000; // 2026-10-09T06:00:00Z exclusive source end.
    public const MAX_BATCH = 1440;
    private const COMPACT_CONTEXT_KEYS = [
        'close', 'rsi', 'ema_20', 'ema_50', 'ema_200', 'macd_hist', 'vwap', 'atr', 'adx',
        'ma9', 'ma21', 'bb_upper', 'bb_middle', 'bb_lower', 'ema', 'ema_prev', 'ema_200_slope',
        'macd', 'pullback_age_bars', 'volume_ratio', 'ma_21_plus_k_atr',
    ];
    private ?ResearchRollingWindows $windows = null;
    private ?LineageContext $lineage = null;
    /** @var array<string, mixed> */
    private array $binding = [];
    /** @var array<string, mixed> */
    private array $baseline = [];
    /** @var array<string, mixed> */
    private array $snapshot = [];
    /** @var array<string, array<string, mixed>> */
    private array $contextCache = [];
    /** @var array<string, array<string, mixed>> */
    private array $compactContextCache = [];
    /** @var array<string, int> */
    private array $contextTimes = [];
    /** @var array<string, int> */
    private array $diagnosticCounts = [];
    private int $consumed = 0;
    private int $warmup = 0;
    private int $evaluated = 0;
    private int $passed = 0;
    private int $beforeScore = 0;

    public function __construct(
        private readonly EffectiveTradingConfigResolverInterface $resolver,
        private readonly CanonicalSetupRuleRuntime $runtime,
        private readonly CanonicalPhpIndicatorCalculator $calculator,
    ) {
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    public function open(array $frame): array
    {
        if ($this->windows !== null) { throw new \InvalidArgumentException('research_session_already_open'); }
        ResearchCandle::exactKeys($frame, ['schema_version', 'session_id', 'dataset_id', 'dataset_sha256', 'source_venue', 'source_network', 'market_type', 'symbol', 'start_ms', 'end_ms', 'score_start_ms']);
        if ($frame['schema_version'] !== 'research-signal-open.v1' || $frame['source_venue'] !== 'binance_usdm'
            || $frame['source_network'] !== 'mainnet' || $frame['market_type'] !== 'perpetual'
            || !is_string($frame['symbol']) || !in_array($frame['symbol'], ResearchCandle::SYMBOLS, true)
            || !is_string($frame['dataset_sha256']) || preg_match('/\A[0-9a-f]{64}\z/D', $frame['dataset_sha256']) !== 1) {
            throw new \InvalidArgumentException('research_source_binding_invalid');
        }
        foreach (['session_id', 'dataset_id'] as $key) {
            if (!is_string($frame[$key]) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $frame[$key]) !== 1) { throw new \InvalidArgumentException('research_session_id_invalid'); }
        }
        foreach (['start_ms', 'end_ms', 'score_start_ms'] as $key) {
            if (!is_int($frame[$key]) || $frame[$key] < 0 || $frame[$key] % 60000 !== 0) { throw new \InvalidArgumentException('research_session_time_invalid'); }
        }
        if ($frame['end_ms'] > self::FROZEN_END_MS || $frame['start_ms'] >= $frame['end_ms']
            || $frame['score_start_ms'] < $frame['start_ms'] || $frame['score_start_ms'] >= $frame['end_ms']) { throw new \InvalidArgumentException('research_session_time_invalid'); }
        $request = new EffectiveTradingConfigRequest('day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0', 'fake', 'local', 'long', ShadowExecutionCapability::Backtest);
        $snapshot = $this->resolver->resolve($request)->toArray();
        if (($snapshot['executable'] ?? null) !== true || ($snapshot['blockers'] ?? null) !== [] || ($snapshot['request'] ?? null) !== $request->toArray()) {
            throw new \InvalidArgumentException('research_baseline_not_executable');
        }
        $canonical = CanonicalEffectiveConfigSnapshot::fromArray($snapshot, [...$request->toArray(), 'config_hash' => $snapshot['config_hash'], 'condition_catalog_hash' => $snapshot['condition_catalog_hash']]);
        $fileHashes = [];
        foreach ($snapshot['ordered_files'] as $file) {
            $digest = hash_file('sha256', $file);
            if ($digest === false) { throw new \InvalidArgumentException('research_baseline_file_unavailable'); }
            $fileHashes[$file] = 'sha256:' . $digest;
        }
        $setup = (new SetupContractLoader())->load($request->setupId, $request->setupVersion);
        $this->baseline = ['config_hash' => $snapshot['config_hash'], 'condition_catalog_hash' => $snapshot['condition_catalog_hash'], 'snapshot_hash' => $snapshot['snapshot_hash'], 'setup_hash' => $setup->stableHash(), 'file_hashes' => $fileHashes, 'mode_risk' => $snapshot['config']['mode']['risk']];
        $this->lineage = LineageContext::fromOrchestratorPayload([
            ...$request->toArray(), 'side' => 'LONG', 'origin' => LineageContext::ORIGIN_REPLAY,
            'orchestration_run_id' => 'research-' . substr(hash('sha256', $frame['session_id']), 0, 24),
            'correlation_run_id' => 'research-' . substr(hash('sha256', $frame['session_id']), 0, 24),
            'orchestration_set_id' => 'research-set-' . substr(hash('sha256', $frame['dataset_id']), 0, 24),
            'replay_of_run_id' => 'research-source-' . substr($frame['dataset_sha256'], 0, 24),
            'symbol' => $frame['symbol'], 'market_type' => 'perpetual', 'dry_run' => true,
            'config_hash' => $snapshot['config_hash'], 'condition_catalog_hash' => $snapshot['condition_catalog_hash'],
            'effective_config_reference' => 'effective-config-snapshot:' . $snapshot['snapshot_hash'], 'effective_config_snapshot' => $canonical->toArray(),
        ]);
        $this->binding = $frame;
        $this->snapshot = $snapshot;
        $this->contextCache = $this->compactContextCache = $this->contextTimes = $this->diagnosticCounts = [];
        $this->consumed = $this->warmup = $this->evaluated = $this->passed = $this->beforeScore = 0;
        $this->windows = new ResearchRollingWindows($frame['symbol'], $frame['start_ms'], $frame['end_ms']);
        return ['schema_version' => 'research-signal-opened.v1', ...$this->identity(), 'effective_config_snapshot' => $this->snapshot, 'indicator_engine_version' => 'php_fallback_v1'];
    }

    /**
     * @param array<string, mixed> $frame
     * @return list<array<string, mixed>>
     */
    public function appendBatch(array $frame): array
    {
        $this->assertSession($frame, 'research-signal-candles.v1', ['schema_version', 'session_id', 'candles']);
        $records = $frame['candles'];
        if (!is_array($records) || !array_is_list($records) || $records === [] || count($records) > self::MAX_BATCH) { throw new \InvalidArgumentException('research_candle_batch_invalid'); }
        $this->assertBaselineFilesUnchanged();
        // Validate the complete batch before changing state, including its promised times.
        $candles = []; $next = $this->binding['start_ms'] + $this->consumed * 60000;
        foreach ($records as $record) {
            if (!is_array($record) || array_is_list($record)) { throw new \InvalidArgumentException('research_candle_batch_invalid'); }
            $candle = ResearchCandle::fromArray($record);
            if ($candle->symbol !== $this->binding['symbol']) { throw new \InvalidArgumentException('research_candle_symbol_mismatch'); }
            if ($candle->openMs !== $next) { throw new \InvalidArgumentException('research_candle_chronology_invalid'); }
            $next += 60000;
            if ($next > $this->binding['end_ms']) { throw new \InvalidArgumentException('research_candle_future_availability'); }
            $candles[] = $candle;
        }
        $results = [];
        foreach ($candles as $candle) {
            $this->windows->append($candle); ++$this->consumed;
            $tick = $candle->openMs + 60000;
            if ($tick % 900000 !== 0) { continue; }
            if (!$this->windows->ready()) { ++$this->warmup; continue; }
            if ($tick < $this->binding['score_start_ms']) { ++$this->beforeScore; continue; }
            $results[] = $this->evaluate($tick);
        }
        return $results;
    }

    /** @return array<string, mixed> */
    private function evaluate(int $tick): array
    {
        $this->assertBaselineFilesUnchanged();
        foreach (array_keys(ResearchRollingWindows::STEPS) as $tf) {
            $bars = $this->windows->bars($tf);
            $last = $bars[array_key_last($bars)];
            if (($this->contextTimes[$tf] ?? null) !== $last['open_ms']) {
                $this->contextTimes[$tf] = $last['open_ms'];
                $this->contextCache[$tf] = [...$this->calculator->calculateNumericSeries($this->windows->numericSeries($tf)),
                    'snapshot_identity' => ['timeframe' => $tf, 'symbol' => $this->binding['symbol'], 'exchange' => 'fake', 'environment' => 'local', 'market_type' => 'perpetual'],
                    'kline_time' => self::instant($last['open_ms'])];
                $this->compactContextCache[$tf] = [
                    'open_ms' => $last['open_ms'],
                    'available_ms' => $last['available_ms'],
                    'research_window_hash' => CanonicalBacktestRuleEvaluator::canonicalHash(['bars' => $bars]),
                    ...array_intersect_key($this->contextCache[$tf], array_flip(self::COMPACT_CONTEXT_KEYS)),
                ];
            }
        }
        // Match canonical command arithmetic type normalization before invoking the same runtime.
        $contexts = json_decode(CanonicalBacktestRuleEvaluator::canonicalJson($this->contextCache), true, 128, JSON_THROW_ON_ERROR);
        $evaluatedAt = self::instant($tick);
        $decision = $this->runtime->evaluate($this->lineage, $contexts, new \DateTimeImmutable($evaluatedAt));
        $trace = $decision->trace; unset($trace['plan_cache_hit']);
        foreach (['setup_hash', 'config_hash'] as $key) {
            if (isset($trace[$key]) && $trace[$key] !== $this->baseline[$key]) {
                throw new \InvalidArgumentException('research_baseline_identity_changed');
            }
        }
        if (array_key_exists('evaluated_at', $trace)) { $trace['evaluated_at'] = $evaluatedAt; }
        ++$this->evaluated; if ($decision->passed) { ++$this->passed; }
        $sampleCount = $this->diagnosticCounts[$decision->reasonCode] ?? 0;
        $retain = $decision->passed || $sampleCount < 3;
        $this->diagnosticCounts[$decision->reasonCode] = min(3, $sampleCount + 1);
        $result = ['schema_version' => 'research-signal-result.v1', ...$this->identity(), 'symbol' => $this->binding['symbol'],
            'evaluated_ms' => $tick, 'evaluated_at' => $evaluatedAt, 'passed' => $decision->passed, 'reason_code' => $decision->reasonCode,
            'numeric_context_hash' => CanonicalBacktestRuleEvaluator::canonicalHash($contexts), 'contexts' => $this->compactContextCache,
            'trace_hash' => CanonicalBacktestRuleEvaluator::canonicalHash($trace), 'verdicts' => $decision->verdicts, 'trace' => $retain ? $trace : null];
        $result['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($result);
        return $result;
    }

    private function assertBaselineFilesUnchanged(): void
    {
        foreach ($this->baseline['file_hashes'] as $file => $expected) {
            if (!is_file($file) || 'sha256:' . hash_file('sha256', $file) !== $expected) {
                throw new \InvalidArgumentException('research_baseline_file_changed');
            }
        }
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return ['session_id' => $this->binding['session_id'], 'source' => array_intersect_key($this->binding, array_flip(['dataset_id', 'dataset_sha256', 'source_venue', 'source_network', 'market_type'])), 'execution' => $this->snapshot['request'], 'baseline' => $this->baseline];
    }

    /**
     * @param array<string, mixed> $frame
     * @param list<string> $keys
     */
    private function assertSession(array $frame, string $schema, array $keys): void
    {
        ResearchCandle::exactKeys($frame, $keys);
        if ($this->windows === null) { throw new \InvalidArgumentException('research_session_not_open'); }
        if ($frame['schema_version'] !== $schema || $frame['session_id'] !== $this->binding['session_id']) { throw new \InvalidArgumentException('research_session_frame_mismatch'); }
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    public function close(array $frame): array
    {
        $this->assertSession($frame, 'research-signal-close.v1', ['schema_version', 'session_id']);
        $end = $this->binding['start_ms'] + $this->consumed * 60000;
        $summary = ['schema_version' => 'research-signal-summary.v1', ...$this->identity(), 'symbol' => $this->binding['symbol'],
            'consumed_candles' => $this->consumed, 'warmup_unavailable' => $this->warmup, 'before_score_start' => $this->beforeScore,
            'evaluated_ticks' => $this->evaluated, 'passed_rules' => $this->passed, 'failed_rules' => $this->evaluated - $this->passed,
            'first_source_open_ms' => $this->consumed > 0 ? $this->binding['start_ms'] : null,
            'last_source_open_ms' => $this->consumed > 0 ? $end - 60000 : null, 'source_available_end_ms' => $end,
            'promised_end_ms' => $this->binding['end_ms'], 'score_start_ms' => $this->binding['score_start_ms'],
            'completion' => $end === $this->binding['end_ms'] ? 'complete' : 'partial'];
        $this->windows = null;
        $this->lineage = null;
        $this->contextCache = $this->compactContextCache = $this->contextTimes = [];
        return $summary;
    }

    public static function instant(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . '.000000Z';
    }
}
