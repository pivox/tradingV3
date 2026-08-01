<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

use App\TradingCore\Setup\Exception\SetupContractException;

final class SetupContractValidator
{
    public const PROVENANCE_PATHS = [
        'context.regime', 'context.context', 'context.trigger', 'context.confirmations', 'filters', 'filters.lev_bounds',
    ];
    public const SETUP_IDS = [
        'day_trading.trend_continuation.long', 'day_trading.trend_continuation.short',
        'scalping.trend_continuation.long', 'scalping.pullback.long', 'scalping.trend_momentum.short',
        'micro_scalping.momentum_ofi.long', 'micro_scalping.momentum_ofi.short', 'crash_short',
    ];
    public const CONDITION_IDS = [
        'adx_min_for_trend', 'atr_rel_in_range_15m', 'atr_rel_in_range_5m', 'atr_volatility_ok',
        'close_above_vwap_and_ma9', 'close_above_vwap_or_ma9', 'close_above_vwap_or_ma9_relaxed',
        'close_below_vwap', 'close_below_vwap_or_ma9', 'crash_context_ok', 'crash_short_pattern_15m',
        'crash_short_pattern_5m', 'crash_short_pattern_1m', 'ema20_over_50_with_tolerance',
        'ema20_over_50_with_tolerance_moderate', 'ema_20_lt_50', 'ema_50_lt_200',
        'macd_hist_decreasing_n', 'macd_hist_gt_eps', 'macd_hist_increasing_n', 'macd_hist_lt_eps',
        'macd_hist_slope_neg', 'macd_hist_slope_pos', 'macd_line_above_signal', 'macd_line_below_signal',
        'macd_line_cross_down_with_hysteresis', 'macd_line_cross_up_with_hysteresis', 'near_vwap',
        'order_flow_imbalance_gte', 'order_flow_imbalance_lte', 'price_regime_ok_long',
        'price_regime_ok_short', 'pullback_confirmed', 'rsi_bearish', 'rsi_bullish', 'rsi_gt_30',
        'rsi_gt_softfloor', 'rsi_lt_70', 'rsi_1m_lt_extreme', 'rsi_5m_gt_floor',
        'spread_bps_lte', 'volume_ratio_ok',
        'ema_50_gt_200', 'ema_above_200_with_tolerance', 'ema_below_200_with_tolerance',
        'close_above_ema_200', 'close_below_ema_200', 'ema200_slope_pos', 'ema200_slope_neg',
        'pullback_confirmed_ma9_21', 'pullback_confirmed_vwap', 'price_lte_ma21_plus_k_atr',
        'crash_short_entry_1m', 'adx_min_for_trend_1h', 'lev_bounds',
    ];
    private const TIMEFRAMES = ['4h', '1h', '15m', '5m', '1m', 'global'];
    /** @var array<string, list<string>> */
    private const PARAMETER_KEYS = [
        'adx_min_for_trend' => ['threshold'],
        'atr_volatility_ok' => ['min_atr_pct', 'max_atr_pct'],
        'macd_hist_decreasing_n' => ['n', 'eps'],
        'macd_hist_gt_eps' => ['eps'],
        'macd_hist_increasing_n' => ['macd_hist_increasing_n'],
        'macd_hist_lt_eps' => ['eps'],
        'near_vwap' => ['near_vwap_tolerance'],
        'order_flow_imbalance_gte' => ['min_ofi'],
        'order_flow_imbalance_lte' => ['max_ofi'],
        'pullback_confirmed' => ['validity_bars', 'direction'],
        'rsi_gt_softfloor' => ['rsi_softfloor_threshold'],
        'rsi_lt_70' => ['rsi_lt_70_threshold'],
        'rsi_5m_gt_floor' => ['gt'],
        'spread_bps_lte' => ['max_spread_bps'],
    ];
    /** @var array<string, 'number'|'integer'|'string'> */
    private const PARAMETER_TYPES = [
        'threshold' => 'number', 'min_atr_pct' => 'number', 'max_atr_pct' => 'number',
        'n' => 'integer', 'eps' => 'number', 'macd_hist_increasing_n' => 'integer',
        'near_vwap_tolerance' => 'number', 'min_ofi' => 'number', 'max_ofi' => 'number',
        'validity_bars' => 'integer', 'direction' => 'string', 'rsi_softfloor_threshold' => 'number',
        'rsi_lt_70_threshold' => 'number', 'gt' => 'number', 'max_spread_bps' => 'number',
    ];
    private const STATUSES = ['draft', 'blocked', 'shadow', 'paper', 'candidate', 'active', 'retired'];
    private const TOP_KEYS = [
        'schema_version', 'setup_id', 'setup_version', 'status', 'executable', 'family', 'side', 'thesis',
        'source_origin', 'compatible_modes', 'mode_compatibility', 'hypothesis', 'context', 'filters',
        'no_trade_rules', 'missing_data_policy', 'execution', 'data_condition_contract', 'validity_window',
        'governance', 'known_defects', 'provenance', 'ownership_model',
    ];
    private const EXPECTED = [
        'day_trading.trend_continuation.long' => ['draft', 'long', 'day_trading'],
        'day_trading.trend_continuation.short' => ['blocked', 'short', 'day_trading'],
        'scalping.trend_continuation.long' => ['draft', 'long', 'scalping'],
        'scalping.pullback.long' => ['draft', 'long', 'scalping'],
        'scalping.trend_momentum.short' => ['draft', 'short', 'scalping'],
        'micro_scalping.momentum_ofi.long' => ['blocked', 'long', 'micro_scalping'],
        'micro_scalping.momentum_ofi.short' => ['blocked', 'short', 'micro_scalping'],
        'crash_short' => ['draft', 'short', null],
    ];

    /** @param array<string, mixed> $document */
    public function validate(array $document): void
    {
        $this->exact($document, self::TOP_KEYS, 'contract');
        foreach (['schema_version', 'setup_id', 'setup_version', 'status', 'family', 'side', 'thesis', 'hypothesis', 'ownership_model'] as $key) {
            $this->string($document, $key, 'contract');
        }
        if ($document['schema_version'] !== '1.0.0' || $document['setup_version'] !== '1.0.0') {
            throw new SetupContractException('Only setup schema/version 1.0.0 is published; aliases and ranges are forbidden.');
        }
        if (!isset(self::EXPECTED[$document['setup_id']])) {
            throw new SetupContractException(sprintf('Unknown canonical setup id "%s".', $document['setup_id']));
        }
        [$status, $side, $mode] = self::EXPECTED[$document['setup_id']];
        if (!in_array($document['status'], self::STATUSES, true) || $document['status'] !== $status || $document['side'] !== $side) {
            throw new SetupContractException('Setup identity, initial status, or side differs from the frozen catalog.');
        }
        if (!is_bool($document['executable']) || $document['executable'] || !in_array($document['status'], ['draft', 'blocked'], true)) {
            throw new SetupContractException('Extracted source hypotheses must remain non-executable draft or blocked contracts.');
        }
        if ($document['ownership_model'] !== 'setup-contract-ownership-v1') {
            throw new SetupContractException('Unknown setup ownership model.');
        }

        $origin = $this->map($document, 'source_origin', 'contract');
        $this->exact($origin, ['file', 'line_range', 'content_sha256', 'commit'], 'source_origin');
        foreach (['file', 'line_range', 'content_sha256', 'commit'] as $key) {
            $this->string($origin, $key, 'source_origin');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $origin['content_sha256']) !== 1 || preg_match('/^[a-f0-9]{40}$/', $origin['commit']) !== 1) {
            throw new SetupContractException('Source origin hashes must be exact immutable SHA values.');
        }
        if (preg_match('/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/', $origin['line_range']) !== 1) {
            throw new SetupContractException('source_origin.line_range has invalid grammar.');
        }

        $modes = $this->list($document, 'compatible_modes', true, 'contract');
        foreach ($modes as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new SetupContractException('compatible_modes entries must be mappings.');
            }
            $this->exact($row, ['mode_id', 'mode_version'], 'compatible_modes[]');
            if (!in_array($row['mode_id'] ?? null, ['day_trading', 'scalping', 'micro_scalping'], true) || ($row['mode_version'] ?? null) !== '1.0.0') {
                throw new SetupContractException('Compatible modes must reference the frozen #300 modern catalog and exact versions.');
            }
        }
        if ($mode === null ? $modes !== [] : $modes !== [['mode_id' => $mode, 'mode_version' => '1.0.0']]) {
            throw new SetupContractException('Setup/mode compatibility differs from the frozen #300 catalog; crash is the sole unresolved exception.');
        }
        $compatibility = $this->map($document, 'mode_compatibility', 'contract');
        $this->exact($compatibility, ['state', 'issue', 'justification'], 'mode_compatibility');
        if ($mode === null && ($compatibility['state'] !== 'unresolved' || $compatibility['issue'] !== '#310')) {
            throw new SetupContractException('crash_short mode compatibility must remain unresolved pending #310.');
        }
        if ($mode !== null && ($compatibility['state'] !== 'defined' || $compatibility['issue'] !== null)) {
            throw new SetupContractException('Catalogued setup compatibility must be defined without an issue placeholder.');
        }
        $this->string($compatibility, 'justification', 'mode_compatibility');

        $context = $this->map($document, 'context', 'contract');
        $this->exact($context, ['side', 'regime', 'context', 'trigger', 'confirmations'], 'context');
        if (($context['side'] ?? null) !== $side || ($this->map($document, 'execution', 'contract')['side'] ?? null) !== $side) {
            throw new SetupContractException('setup.side=context.side=execution.side invariant violated.');
        }
        foreach (['regime', 'context', 'trigger', 'confirmations'] as $key) {
            $this->expression($this->map($context, $key, 'context'), 'context.' . $key);
        }
        $this->rules($this->list($document, 'filters', true, 'contract'), 'filters');
        $this->rules($this->list($document, 'no_trade_rules', true, 'contract'), 'no_trade_rules');

        $missingPolicy = $this->map($document, 'missing_data_policy', 'contract');
        $this->exact($missingPolicy, ['absent', 'stale', 'critical'], 'missing_data_policy');
        if ($missingPolicy !== ['absent' => 'reject', 'stale' => 'reject', 'critical' => 'reject']) {
            throw new SetupContractException('Missing, stale, or critical absent data must reject fail-closed.');
        }

        $execution = $this->map($document, 'execution', 'contract');
        $this->exact($execution, ['side', 'entry_zone', 'stop', 'targets', 'minimum_net_r', 'invalidation', 'time_stop', 'cost_contract'], 'execution');
        foreach (['entry_zone', 'stop', 'targets', 'minimum_net_r', 'invalidation', 'time_stop', 'cost_contract'] as $key) {
            $this->decision($this->map($execution, $key, 'execution'), 'execution.' . $key);
        }
        $this->decision($this->map($document, 'validity_window', 'contract'), 'validity_window');

        $data = $this->map($document, 'data_condition_contract', 'contract');
        $this->exact($data, ['required_data', 'missing_conditions', 'external_dependencies', 'condition_catalog_hash', 'unknown_condition_policy'], 'data_condition_contract');
        $requiredData = $this->strings($this->list($data, 'required_data', false, 'data_condition_contract'), 'required_data');
        $this->assertUniqueStrings($requiredData, 'data_condition_contract.required_data');
        $missing = $this->strings($this->list($data, 'missing_conditions', true, 'data_condition_contract'), 'missing_conditions');
        $this->assertUniqueStrings($missing, 'data_condition_contract.missing_conditions');
        foreach ($missing as $condition) {
            if (!in_array($condition, self::CONDITION_IDS, true)) {
                throw new SetupContractException(sprintf('Unknown condition "%s".', $condition));
            }
        }
        $seenDependencies = [];
        foreach ($this->list($data, 'external_dependencies', true, 'data_condition_contract') as $index => $dependency) {
            if (!is_array($dependency) || array_is_list($dependency)) {
                throw new SetupContractException(sprintf('data_condition_contract.external_dependencies[%d] must be a mapping.', $index));
            }
            $identity = $dependency;
            ksort($identity);
            $identityHash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
            if (isset($seenDependencies[$identityHash])) {
                throw new SetupContractException('data_condition_contract.external_dependencies must contain unique items.');
            }
            $seenDependencies[$identityHash] = true;
            $this->exact($dependency, ['dependency_id', 'state', 'owner', 'source', 'justification', 'failure_policy'], 'data_condition_contract.external_dependencies[]');
            foreach (['dependency_id', 'state', 'owner', 'source', 'justification', 'failure_policy'] as $key) {
                $this->string($dependency, $key, 'data_condition_contract.external_dependencies[]');
            }
            if ($dependency['state'] !== 'unresolved' || $dependency['owner'] !== 'mode_or_exchange' || $dependency['failure_policy'] !== 'reject') {
                throw new SetupContractException('External safety dependencies must remain mode_or_exchange-owned, unresolved, and fail closed.');
            }
        }
        $conditionCatalogHash = $this->map($data, 'condition_catalog_hash', 'data_condition_contract');
        $this->decision($conditionCatalogHash, 'data_condition_contract.condition_catalog_hash');
        if ($conditionCatalogHash['unit'] !== 'sha256') {
            throw new SetupContractException('data_condition_contract.condition_catalog_hash.unit must be sha256.');
        }
        if ($conditionCatalogHash['state'] === 'defined' && (!is_string($conditionCatalogHash['value']) || preg_match('/^[a-f0-9]{64}$/', $conditionCatalogHash['value']) !== 1)) {
            throw new SetupContractException('Defined condition catalog hash must be a lowercase 64-character SHA-256 string.');
        }
        if ($data['unknown_condition_policy'] !== 'reject') {
            throw new SetupContractException('Unknown conditions must reject.');
        }
        if ($missing !== [] && $document['status'] !== 'blocked') {
            throw new SetupContractException('Missing conditions require blocked status.');
        }

        $governance = $this->map($document, 'governance', 'contract');
        $this->exact($governance, ['shadow', 'paper', 'promotion', 'suspension', 'rollback', 'activation_requires_trace', 'activation_requires_certified_net_baseline'], 'governance');
        foreach (['shadow', 'paper', 'promotion', 'suspension', 'rollback'] as $key) {
            $this->strings($this->list($governance, $key, false, 'governance'), 'governance.' . $key);
        }
        if (($governance['activation_requires_trace'] ?? null) !== true || ($governance['activation_requires_certified_net_baseline'] ?? null) !== true) {
            throw new SetupContractException('Activation requires trace and a certified net baseline.');
        }
        $knownDefects = $this->strings($this->list($document, 'known_defects', true, 'contract'), 'known_defects');
        $this->assertUniqueStrings($knownDefects, 'known_defects');
        $rows = $this->list($document, 'provenance', false, 'contract');
        $provenancePaths = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new SetupContractException('Provenance entries must be mappings.');
            }
            $this->exact($row, ['path', 'source', 'justification'], 'provenance[]');
            foreach (['path', 'source', 'justification'] as $key) {
                $this->string($row, $key, 'provenance[]');
            }
            $provenancePath = $row['path'];
            if (!in_array($provenancePath, self::PROVENANCE_PATHS, true)) {
                throw new SetupContractException(sprintf('Unknown provenance path "%s".', $provenancePath));
            }
            if (isset($provenancePaths[$provenancePath])) {
                throw new SetupContractException(sprintf('Duplicate provenance path "%s".', $provenancePath));
            }
            $provenancePaths[$provenancePath] = true;
        }
    }

    /** @param list<mixed> $rules */
    private function rules(array $rules, string $path): void
    {
        foreach ($rules as $rule) {
            if (!is_array($rule) || array_is_list($rule)) {
                throw new SetupContractException($path . ' rules must be mappings.');
            }
            $this->expression($rule, $path . '[]');
        }
    }

    /** @param array<string, mixed> $expression */
    private function expression(array $expression, string $path): void
    {
        if (array_key_exists('op', $expression)) {
            $this->exact($expression, ['op', 'nodes', 'provenance'], $path);
            if (!in_array($expression['op'], ['all_of', 'any_of'], true)) {
                throw new SetupContractException($path . '.op must be all_of or any_of.');
            }
            $this->string($expression, 'provenance', $path);
            $nodes = $this->list($expression, 'nodes', false, $path);
            foreach ($nodes as $index => $node) {
                if (!is_array($node) || array_is_list($node)) {
                    throw new SetupContractException(sprintf('%s.nodes[%d] must be an expression mapping.', $path, $index));
                }
                $this->expression($node, sprintf('%s.nodes[%d]', $path, $index));
            }

            return;
        }

        $keys = array_key_exists('parameters', $expression)
            ? ['condition', 'timeframe', 'parameters', 'provenance']
            : ['condition', 'timeframe', 'provenance'];
        $this->exact($expression, $keys, $path);
        $this->string($expression, 'condition', $path);
        $this->string($expression, 'timeframe', $path);
        $this->string($expression, 'provenance', $path);
        $condition = $expression['condition'];
        if (!in_array($condition, self::CONDITION_IDS, true)) {
            throw new SetupContractException(sprintf('Unknown condition "%s".', $condition));
        }
        if (!in_array($expression['timeframe'], self::TIMEFRAMES, true)) {
            throw new SetupContractException(sprintf('Unsupported rule timeframe "%s".', $expression['timeframe']));
        }
        if (!array_key_exists('parameters', $expression)) {
            return;
        }
        $parameters = $expression['parameters'];
        if (!is_array($parameters) || array_is_list($parameters)) {
            throw new SetupContractException($path . '.parameters must be a mapping.');
        }
        $allowed = self::PARAMETER_KEYS[$condition] ?? [];
        $extra = array_diff(array_keys($parameters), $allowed);
        if ($extra !== []) {
            throw new SetupContractException(sprintf('Unknown parameter "%s" for condition "%s".', reset($extra), $condition));
        }
        foreach ($parameters as $key => $value) {
            $type = self::PARAMETER_TYPES[$key];
            $valid = match ($type) {
                'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
                'integer' => is_int($value),
                'string' => is_string($value) && trim($value) !== '',
            };
            if (!$valid) {
                throw new SetupContractException(sprintf('Parameter "%s" for condition "%s" must be a finite %s.', $key, $condition, $type));
            }
        }
    }

    /** @param array<string, mixed> $decision */
    private function decision(array $decision, string $path): void
    {
        $this->exact($decision, ['state', 'value', 'unit', 'source', 'justification'], $path);
        if (!in_array($decision['state'] ?? null, ['defined', 'unresolved'], true)) {
            throw new SetupContractException($path . '.state must be defined or unresolved.');
        }
        foreach (['unit', 'source', 'justification'] as $key) {
            $this->string($decision, $key, $path);
        }
        if (($decision['state'] === 'unresolved') !== ($decision['value'] === null)) {
            throw new SetupContractException($path . ' state/value mismatch.');
        }
    }

    /**
     * @param array<string, mixed> $map
     * @param list<string> $keys
     */
    private function exact(array $map, array $keys, string $path): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $map)) {
                throw new SetupContractException(sprintf('Missing required field "%s" at %s.', $key, $path));
            }
        }
        $extra = array_diff(array_keys($map), $keys);
        if ($extra !== []) {
            throw new SetupContractException(sprintf('Unknown field "%s" at %s.', reset($extra), $path));
        }
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function map(array $map, string $key, string $path): array
    {
        if (!isset($map[$key]) || !is_array($map[$key]) || array_is_list($map[$key])) {
            throw new SetupContractException(sprintf('Field "%s" at %s must be a mapping.', $key, $path));
        }

        return $map[$key];
    }

    /**
     * @param array<string, mixed> $map
     * @return list<mixed>
     */
    private function list(array $map, string $key, bool $empty, string $path): array
    {
        $value = $map[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || (!$empty && $value === [])) {
            throw new SetupContractException(sprintf('Field "%s" at %s must be %sa list.', $key, $path, $empty ? '' : 'a non-empty '));
        }

        return $value;
    }

    /** @param array<string, mixed> $map */
    private function string(array $map, string $key, string $path): void
    {
        if (!isset($map[$key]) || !is_string($map[$key]) || trim($map[$key]) === '') {
            throw new SetupContractException(sprintf('Field "%s" at %s must be a non-empty string.', $key, $path));
        }
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function strings(array $values, string $path): array
    {
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new SetupContractException($path . ' must contain only non-empty strings.');
            }
        }

        /** @var list<string> $values */
        return $values;
    }

    /** @param list<string> $values */
    private function assertUniqueStrings(array $values, string $path): void
    {
        if (count(array_unique($values)) !== count($values)) {
            throw new SetupContractException($path . ' must contain unique items.');
        }
    }
}
