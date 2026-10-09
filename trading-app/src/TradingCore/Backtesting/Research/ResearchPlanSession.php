<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use App\TradingCore\Setup\SetupContractLoader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** One persistent, read-only research session across many B1 signal records. */
final class ResearchPlanSession
{
    private const FROZEN_END_MS = 1791525600000;
    private bool $active = false;
    private string $sessionId = '';
    private int $expectedSignals = 0;
    private int $received = 0;
    private int $planned = 0;
    /** @var array<string, int> */
    private array $rejections = [];
    /** @var array<string, ResearchPlanBuilder> */
    private array $builders = [];
    /** @var array<string, int> */
    private array $lastIndex = [];
    /** @var array<string, string> */
    private array $fileHashes = [];
    private string $codeHash = '';

    public function __construct(
        #[Autowire(service: EffectiveTradingConfigResolver::class)]
        private readonly EffectiveTradingConfigResolverInterface $resolver,
    ) {
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    public function open(array $frame): array
    {
        if ($this->active) {
            throw new \InvalidArgumentException('research_plan_session_already_open');
        }
        self::exact($frame, ['schema_version', 'session_id', 'baseline', 'source_runs', 'variant', 'instrument_assumptions', 'cost_assumptions', 'cost_profile', 'expected_signals'], 'research_plan_open_invalid');
        if (($frame['schema_version'] ?? null) !== 'research-plan-open.v1'
            || !is_string($frame['session_id']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $frame['session_id']) !== 1
            || !is_int($frame['expected_signals']) || $frame['expected_signals'] < 0 || $frame['expected_signals'] > 10_000_000
            || !is_array($frame['baseline']) || !is_array($frame['source_runs']) || !array_is_list($frame['source_runs'])
            || $frame['source_runs'] === [] || count($frame['source_runs']) > 10
            || !is_array($frame['variant']) || !is_array($frame['instrument_assumptions'])
            || !is_array($frame['cost_assumptions']) || !is_string($frame['cost_profile'])
        ) {
            throw new \InvalidArgumentException('research_plan_open_invalid');
        }
        $request = new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'local', 'long', ShadowExecutionCapability::Backtest,
        );
        $snapshot = $this->resolver->resolve($request);
        if (!$snapshot->executable || $snapshot->blockers !== []) {
            throw new \InvalidArgumentException('research_plan_baseline_not_executable');
        }
        $setup = (new SetupContractLoader())->load($request->setupId, $request->setupVersion);
        $fileHashes = [];
        foreach ($snapshot->toArray()['ordered_files'] as $file) {
            $digest = hash_file('sha256', $file);
            if ($digest === false) {
                throw new \InvalidArgumentException('research_plan_baseline_file_missing');
            }
            $fileHashes[$file] = 'sha256:' . $digest;
        }
        $catalogPath = dirname(__DIR__, 4) . '/' . $setup->toArray()['data_condition_contract']['condition_catalog_hash']['source'];
        $catalogDigest = hash_file('sha256', $catalogPath);
        if ($catalogDigest === false) {
            throw new \InvalidArgumentException('research_plan_baseline_file_missing');
        }
        $fileHashes[$catalogPath] = 'sha256:' . $catalogDigest;
        $expectedBaseline = ['config_hash' => $snapshot->configHash, 'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'snapshot_hash' => $snapshot->toArray()['snapshot_hash'], 'setup_hash' => $setup->stableHash(),
            'file_hashes' => $fileHashes, 'mode_risk' => $snapshot->payload()['mode']['risk']];
        if (CanonicalBacktestRuleEvaluator::canonicalJson($expectedBaseline) !== CanonicalBacktestRuleEvaluator::canonicalJson($frame['baseline'])) {
            throw new \InvalidArgumentException('research_plan_baseline_mismatch');
        }
        $policy = CanonicalExecutionPolicy::fromSnapshot($snapshot);
        $portfolioPolicy = CanonicalPortfolioPolicy::fromSnapshot($snapshot);
        self::exact($frame['variant'], ['schema_version', 'id', 'diff', 'variant_hash'], 'research_variant_invalid');
        if (($frame['variant']['schema_version'] ?? null) !== 'research-variant-selection.v1'
            || !is_string($frame['variant']['id']) || !is_array($frame['variant']['diff'])
            || !is_string($frame['variant']['variant_hash'])) {
            throw new \InvalidArgumentException('research_variant_invalid');
        }
        $variant = ResearchVariant::select(
            $frame['variant']['id'], $frame['variant']['diff'], $policy, $setup->stableHash(), (string) $snapshot->conditionCatalogHash,
        );
        if (!hash_equals($variant->hash, $frame['variant']['variant_hash'])) {
            throw new \InvalidArgumentException('research_variant_hash_invalid');
        }
        $instruments = ResearchInstrumentAssumptions::fromArray($frame['instrument_assumptions']);
        $costs = ResearchCostAssumptions::fromArray($frame['cost_assumptions']);
        $costs->profile($frame['cost_profile']);
        $builders = [];
        foreach ($frame['source_runs'] as $run) {
            if (!is_array($run) || array_is_list($run)) {
                throw new \InvalidArgumentException('research_plan_source_run_invalid');
            }
            self::validateRun($run);
            $symbol = $run['symbol'];
            if (isset($builders[$symbol])) {
                throw new \InvalidArgumentException('research_plan_source_run_duplicate');
            }
            $instruments->forSymbol($symbol);
            $builders[$symbol] = new ResearchPlanBuilder($policy, $portfolioPolicy, $variant, $instruments, $costs,
                $frame['cost_profile'], $expectedBaseline, $run);
        }
        $this->active = true;
        $this->sessionId = $frame['session_id'];
        $this->expectedSignals = $frame['expected_signals'];
        $this->received = $this->planned = 0;
        $this->rejections = $this->lastIndex = [];
        $this->builders = $builders;
        $this->fileHashes = $fileHashes;
        $this->codeHash = ResearchCodeIdentity::current();

        return ['schema_version' => 'research-plan-opened.v1', 'session_id' => $this->sessionId,
            'variant_id' => $variant->id, 'variant_hash' => $variant->hash,
            'base_config_hash' => $snapshot->configHash, 'base_snapshot_hash' => $expectedBaseline['snapshot_hash'],
            'instrument_assumptions_hash' => $instruments->hash, 'cost_assumptions_hash' => $costs->hash,
            'cost_profile' => $frame['cost_profile'], 'source_run_count' => count($builders),
            'expected_signals' => $this->expectedSignals, 'research_code_hash' => $this->codeHash,
            'code_hash_scope' => 'research_plan_direct_dependencies_v1',
            'execution_authority' => 'none'];
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    public function append(array $frame): array
    {
        self::exact($frame, ['schema_version', 'session_id', 'signal_index', 'signal', 'portfolio'], 'research_plan_signal_frame_invalid');
        if (!$this->active || ($frame['schema_version'] ?? null) !== 'research-plan-signal.v1'
            || ($frame['session_id'] ?? null) !== $this->sessionId
            || !is_int($frame['signal_index']) || $frame['signal_index'] < 0
            || !is_array($frame['signal']) || !is_array($frame['portfolio'])) {
            throw new \InvalidArgumentException('research_plan_signal_frame_invalid');
        }
        $this->assertFilesUnchanged();
        $symbol = $frame['signal']['symbol'] ?? null;
        if (!is_string($symbol) || !isset($this->builders[$symbol])) {
            throw new \InvalidArgumentException('research_plan_signal_source_unbound');
        }
        if ($frame['signal_index'] <= ($this->lastIndex[$symbol] ?? -1)) {
            throw new \InvalidArgumentException('research_plan_signal_index_invalid');
        }
        if ($this->received >= $this->expectedSignals) {
            throw new \InvalidArgumentException('research_plan_signal_count_exceeded');
        }
        $result = $this->builders[$symbol]->build($frame['signal'], $frame['signal_index'], $frame['portfolio']);
        $this->lastIndex[$symbol] = $frame['signal_index'];
        ++$this->received;
        if ($result['schema_version'] === 'research-plan.v1') {
            ++$this->planned;
        } else {
            $reason = $result['reason_code'];
            $this->rejections[$reason] = ($this->rejections[$reason] ?? 0) + 1;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    public function close(array $frame): array
    {
        self::exact($frame, ['schema_version', 'session_id', 'expected_signals'], 'research_plan_close_invalid');
        if (!$this->active || ($frame['schema_version'] ?? null) !== 'research-plan-close.v1'
            || ($frame['session_id'] ?? null) !== $this->sessionId
            || ($frame['expected_signals'] ?? null) !== $this->expectedSignals
            || $this->received !== $this->expectedSignals) {
            throw new \InvalidArgumentException('research_plan_close_invalid');
        }
        $this->assertFilesUnchanged();
        $this->active = false;
        return ['schema_version' => 'research-plan-summary.v1', 'session_id' => $this->sessionId,
            'received' => $this->received, 'planned' => $this->planned,
            'rejected' => $this->received - $this->planned, 'rejection_counts' => $this->rejections,
            'completion' => 'complete', 'execution_authority' => 'none'];
    }

    /** @param array<string, mixed> $run */
    private static function validateRun(array $run): void
    {
        self::exact($run, ['symbol', 'source', 'signal_session_id', 'signal_run_id', 'signal_output_sha256', 'start_ms', 'score_start_ms', 'end_ms'], 'research_plan_source_run_invalid');
        $source = $run['source'] ?? null;
        if (!is_array($source) || array_is_list($source)) {
            throw new \InvalidArgumentException('research_plan_source_run_invalid');
        }
        self::exact($source, ['dataset_id', 'dataset_sha256', 'source_venue', 'source_network', 'market_type'], 'research_plan_source_run_invalid');
        if (!is_string($run['symbol']) || !in_array($run['symbol'], ResearchInstrumentAssumptions::SYMBOLS, true)
            || $source['source_venue'] !== 'binance_usdm' || $source['source_network'] !== 'mainnet' || $source['market_type'] !== 'perpetual'
            || !is_string($source['dataset_id']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $source['dataset_id']) !== 1
            || !is_string($source['dataset_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $source['dataset_sha256']) !== 1
            || !is_string($run['signal_session_id']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $run['signal_session_id']) !== 1
            || !is_string($run['signal_run_id']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $run['signal_run_id']) !== 1
            || !is_string($run['signal_output_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $run['signal_output_sha256']) !== 1
        ) {
            throw new \InvalidArgumentException('research_plan_source_run_invalid');
        }
        foreach (['start_ms', 'score_start_ms', 'end_ms'] as $key) {
            if (!is_int($run[$key]) || $run[$key] < 0 || $run[$key] % 60000 !== 0) {
                throw new \InvalidArgumentException('research_plan_source_run_invalid');
            }
        }
        if ($run['start_ms'] > $run['score_start_ms'] || $run['score_start_ms'] >= $run['end_ms']
            || $run['end_ms'] > self::FROZEN_END_MS) {
            throw new \InvalidArgumentException('research_plan_source_run_invalid');
        }
    }

    private function assertFilesUnchanged(): void
    {
        if (ResearchCodeIdentity::current() !== $this->codeHash) {
            throw new \InvalidArgumentException('research_plan_code_changed');
        }
        foreach ($this->fileHashes as $file => $expected) {
            if (!is_file($file) || 'sha256:' . hash_file('sha256', $file) !== $expected) {
                throw new \InvalidArgumentException('research_plan_baseline_file_changed');
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function exact(array $value, array $expected, string $reason): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException($reason);
        }
    }
}
