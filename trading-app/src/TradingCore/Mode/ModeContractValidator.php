<?php

declare(strict_types=1);

namespace App\TradingCore\Mode;

use App\TradingCore\Mode\Exception\ModeContractException;

final class ModeContractValidator
{
    public const MODE_IDS = ['day_trading', 'scalping', 'micro_scalping'];
    public const LIFECYCLE_STATUSES = ['draft', 'shadow', 'paper', 'candidate', 'active', 'retired'];
    private const TIMEFRAMES = ['4h', '1h', '15m', '5m', '1m'];
    private const PUBLISHED_VERSIONS = [
        'day_trading' => ['1.0.0'],
        'scalping' => ['1.0.0'],
        'micro_scalping' => ['1.0.0'],
    ];
    private const GOVERNANCE_TARGETS = [
        'promotion' => 'shadow',
        'suspension' => 'draft',
        'rollback' => 'retired',
    ];

    private const SETUP_IDS = [
        'day_trading' => [
            'day_trading.trend_continuation.long',
            'day_trading.trend_continuation.short',
        ],
        'scalping' => [
            'scalping.trend_continuation.long',
            'scalping.pullback.long',
            'scalping.trend_momentum.short',
        ],
        'micro_scalping' => [
            'micro_scalping.momentum_ofi.long',
            'micro_scalping.momentum_ofi.short',
        ],
    ];

    private const TOP_LEVEL_KEYS = [
        'schema_version', 'mode_id', 'mode_version', 'lifecycle', 'horizon', 'session_policy',
        'timeframes', 'cadence', 'risk', 'leverage', 'order_policy', 'compatible_setup_ids',
        'data_contract', 'governance', 'ownership_model', 'provenance',
    ];

    /** @param array<string, mixed> $document */
    public function validate(array $document): void
    {
        $this->assertExactKeys($document, self::TOP_LEVEL_KEYS, 'contract');
        $this->assertString($document, 'schema_version');
        $this->assertString($document, 'mode_id');
        $this->assertString($document, 'mode_version');

        if ($document['schema_version'] !== '1.0.0') {
            throw new ModeContractException(sprintf('Unsupported mode contract schema version "%s".', $document['schema_version']));
        }
        if (!in_array($document['mode_id'], self::MODE_IDS, true)) {
            throw new ModeContractException(sprintf('Unknown modern mode id "%s".', $document['mode_id']));
        }
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/', $document['mode_version']) !== 1) {
            throw new ModeContractException('mode_version must be a semantic version without aliases or ranges.');
        }
        if (!in_array($document['mode_version'], self::PUBLISHED_VERSIONS[$document['mode_id']], true)) {
            throw new ModeContractException(sprintf(
                'Unsupported published version "%s" for modern mode "%s".',
                $document['mode_version'],
                $document['mode_id'],
            ));
        }

        $lifecycle = $this->mapping($document, 'lifecycle');
        $this->assertExactKeys($lifecycle, ['status', 'executable', 'rationale'], 'lifecycle');
        if (!in_array($lifecycle['status'], self::LIFECYCLE_STATUSES, true)) {
            throw new ModeContractException('lifecycle.status is invalid.');
        }
        if (!is_bool($lifecycle['executable']) || !is_string($lifecycle['rationale']) || trim($lifecycle['rationale']) === '') {
            throw new ModeContractException('lifecycle executable/rationale are invalid.');
        }
        if (in_array($lifecycle['status'], ['draft', 'retired'], true) && $lifecycle['executable']) {
            throw new ModeContractException('draft and retired contracts cannot be executable.');
        }

        $this->assertDecision($this->mapping($document, 'horizon'), 'horizon');
        $this->assertDecision($this->mapping($document, 'session_policy'), 'session_policy');
        $this->assertUnit($this->mapping($document, 'horizon'), 'horizon', 'holding_horizon_policy');
        $this->assertUnit($this->mapping($document, 'session_policy'), 'session_policy', 'session_policy');
        $this->assertDefinedString($this->mapping($document, 'horizon'), 'horizon');
        $this->assertDefinedString($this->mapping($document, 'session_policy'), 'session_policy');
        $this->assertTimeframes($this->mapping($document, 'timeframes'));

        $cadence = $this->mapping($document, 'cadence');
        $this->assertExactKeys($cadence, ['evaluation', 'validity_window'], 'cadence');
        $this->assertDecision($this->mapping($cadence, 'evaluation'), 'cadence.evaluation');
        $this->assertDecision($this->mapping($cadence, 'validity_window'), 'cadence.validity_window');
        $this->assertDefinedDuration($this->mapping($cadence, 'evaluation'), 'cadence.evaluation');
        $this->assertDefinedDuration($this->mapping($cadence, 'validity_window'), 'cadence.validity_window');
        $this->assertUnit($this->mapping($cadence, 'evaluation'), 'cadence.evaluation', 'duration');
        $this->assertUnit($this->mapping($cadence, 'validity_window'), 'cadence.validity_window', 'duration');

        $risk = $this->mapping($document, 'risk');
        $this->assertExactKeys($risk, ['trade_budget', 'daily_loss_cap', 'max_concurrent_positions', 'mode_exposure_cap'], 'risk');
        foreach (array_keys($risk) as $key) {
            $this->assertDecision($this->mapping($risk, $key), 'risk.' . $key);
        }
        $this->assertUnit($this->mapping($risk, 'trade_budget'), 'risk.trade_budget', 'percent_equity_per_trade');
        $this->assertUnit($this->mapping($risk, 'daily_loss_cap'), 'risk.daily_loss_cap', 'compound_percent_equity_and_quote_per_day');
        $this->assertUnit($this->mapping($risk, 'max_concurrent_positions'), 'risk.max_concurrent_positions', 'positions');
        $this->assertUnit($this->mapping($risk, 'mode_exposure_cap'), 'risk.mode_exposure_cap', 'percent_equity_notional');
        $this->assertDefinedPositiveNumber($this->mapping($risk, 'trade_budget'), 'risk.trade_budget');
        $this->assertDefinedDailyCap($this->mapping($risk, 'daily_loss_cap'));
        $this->assertDefinedPositiveInteger($this->mapping($risk, 'max_concurrent_positions'), 'risk.max_concurrent_positions');
        $this->assertDefinedPositiveNumber($this->mapping($risk, 'mode_exposure_cap'), 'risk.mode_exposure_cap');
        $leverage = $this->mapping($document, 'leverage');
        $this->assertDecision($leverage, 'leverage');
        $this->assertUnit($leverage, 'leverage', 'leverage_multiple');
        $this->assertDefinedPositiveNumber($leverage, 'leverage');
        $orderPolicy = $this->mapping($document, 'order_policy');
        $this->assertDecision($orderPolicy, 'order_policy');
        $this->assertUnit($orderPolicy, 'order_policy', 'policy');
        $this->assertDefinedOrderPolicy($orderPolicy);

        $setupIds = $this->stringList($document, 'compatible_setup_ids', false);
        foreach ($setupIds as $setupId) {
            if (!str_starts_with($setupId, $document['mode_id'] . '.')) {
                throw new ModeContractException(sprintf('Setup "%s" is not namespaced by mode "%s".', $setupId, $document['mode_id']));
            }
        }
        if ($setupIds !== self::SETUP_IDS[$document['mode_id']]) {
            throw new ModeContractException(sprintf('compatible_setup_ids do not match the frozen catalog for "%s".', $document['mode_id']));
        }

        $data = $this->mapping($document, 'data_contract');
        $this->assertExactKeys($data, ['required_inputs', 'missing_data_policy'], 'data_contract');
        if (!is_array($data['required_inputs']) || !array_is_list($data['required_inputs']) || $data['required_inputs'] === []) {
            throw new ModeContractException('data_contract.required_inputs must be a non-empty list.');
        }
        foreach ($data['required_inputs'] as $input) {
            if (!is_array($input) || array_is_list($input)) {
                throw new ModeContractException('Each required input must be a mapping.');
            }
            $this->assertExactKeys($input, ['kind', 'timeframes', 'fields'], 'data_contract.required_inputs[]');
            $this->assertString($input, 'kind');
            if (!in_array($input['kind'], ['candles', 'order_book'], true)) {
                throw new ModeContractException('data_contract required input kind is invalid.');
            }
            foreach ($this->stringList($input, 'timeframes', false) as $timeframe) {
                if (!in_array($timeframe, self::TIMEFRAMES, true)) {
                    throw new ModeContractException(sprintf('Unsupported timeframe "%s" in data_contract.required_inputs[].timeframes.', $timeframe));
                }
            }
            $this->stringList($input, 'fields', false);
        }
        if ($data['missing_data_policy'] !== 'reject') {
            throw new ModeContractException('Missing required data must reject.');
        }

        $governance = $this->mapping($document, 'governance');
        $this->assertExactKeys($governance, ['promotion', 'suspension', 'rollback'], 'governance');
        foreach (['promotion', 'suspension', 'rollback'] as $rule) {
            $value = $this->mapping($governance, $rule);
            $this->assertExactKeys($value, ['target_status', 'conditions', 'action'], 'governance.' . $rule);
            $this->assertString($value, 'target_status');
            if (!in_array($value['target_status'], self::LIFECYCLE_STATUSES, true)) {
                throw new ModeContractException(sprintf('governance.%s.target_status is invalid.', $rule));
            }
            if ($value['target_status'] !== self::GOVERNANCE_TARGETS[$rule]) {
                throw new ModeContractException(sprintf(
                    'governance.%s.target_status must be "%s".',
                    $rule,
                    self::GOVERNANCE_TARGETS[$rule],
                ));
            }
            $this->stringList($value, 'conditions', false);
            $this->assertString($value, 'action');
        }

        if ($document['ownership_model'] !== 'mode-contract-ownership-v1') {
            throw new ModeContractException('Unknown ownership_model.');
        }
        if (!is_array($document['provenance']) || !array_is_list($document['provenance']) || $document['provenance'] === []) {
            throw new ModeContractException('provenance must be a non-empty list.');
        }
        foreach ($document['provenance'] as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ModeContractException('Each provenance row must be a mapping.');
            }
            $this->assertExactKeys($row, ['path', 'source', 'unit', 'justification'], 'provenance[]');
            foreach (['path', 'source', 'unit', 'justification'] as $field) {
                $this->assertString($row, $field);
            }
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDecision(array $decision, string $path): void
    {
        $this->assertExactKeys($decision, ['state', 'value', 'unit', 'source', 'justification'], $path);
        if (!in_array($decision['state'], ['defined', 'unresolved'], true)) {
            throw new ModeContractException(sprintf('%s.state must be defined or unresolved.', $path));
        }
        foreach (['unit', 'source', 'justification'] as $field) {
            $this->assertString($decision, $field);
        }
        if ($decision['state'] === 'unresolved' && $decision['value'] !== null) {
            throw new ModeContractException(sprintf('%s unresolved value must be null.', $path));
        }
        if ($decision['state'] === 'defined' && $decision['value'] === null) {
            throw new ModeContractException(sprintf('%s defined value cannot be null.', $path));
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertUnit(array $decision, string $path, string $expected): void
    {
        if ($decision['unit'] !== $expected) {
            throw new ModeContractException(sprintf('%s.unit must be "%s".', $path, $expected));
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDefinedString(array $decision, string $path): void
    {
        if ($decision['state'] === 'defined' && (!is_string($decision['value']) || trim($decision['value']) === '')) {
            throw new ModeContractException(sprintf('%s defined value must be a non-empty string.', $path));
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDefinedDuration(array $decision, string $path): void
    {
        if ($decision['state'] !== 'defined') {
            return;
        }
        $value = $decision['value'];
        $pattern = '/^P(?=\d|T\d)(?:\d+Y)?(?:\d+M)?(?:\d+D)?(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+S)?)?$/';
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new ModeContractException(sprintf('%s defined value must be an ISO-8601 duration.', $path));
        }
        try {
            new \DateInterval($value);
        } catch (\Exception) {
            throw new ModeContractException(sprintf('%s defined value must be an ISO-8601 duration.', $path));
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDefinedPositiveNumber(array $decision, string $path): void
    {
        if ($decision['state'] === 'defined' && (!is_int($decision['value']) && !is_float($decision['value']) || $decision['value'] <= 0 || !is_finite((float) $decision['value']))) {
            throw new ModeContractException(sprintf('%s defined value must be a positive number.', $path));
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDefinedPositiveInteger(array $decision, string $path): void
    {
        if ($decision['state'] === 'defined' && (!is_int($decision['value']) || $decision['value'] < 1)) {
            throw new ModeContractException(sprintf('%s defined value must be a positive integer.', $path));
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDefinedDailyCap(array $decision): void
    {
        if ($decision['state'] !== 'defined') {
            return;
        }
        $value = $decision['value'];
        if (!is_array($value) || array_is_list($value)) {
            throw new ModeContractException('risk.daily_loss_cap defined value must be a cap mapping.');
        }
        $this->assertExactKeys($value, ['percent_equity', 'absolute_quote', 'quote_currency'], 'risk.daily_loss_cap.value');
        if ((!is_int($value['percent_equity']) && !is_float($value['percent_equity'])) || $value['percent_equity'] <= 0 || !is_finite((float) $value['percent_equity'])) {
            throw new ModeContractException('risk.daily_loss_cap percent_equity must be positive.');
        }
        if ((!is_int($value['absolute_quote']) && !is_float($value['absolute_quote'])) || $value['absolute_quote'] <= 0 || !is_finite((float) $value['absolute_quote']) || $value['quote_currency'] !== 'USDT') {
            throw new ModeContractException('risk.daily_loss_cap absolute_quote/currency are invalid.');
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDefinedOrderPolicy(array $decision): void
    {
        if ($decision['state'] !== 'defined') {
            return;
        }
        $value = $decision['value'];
        if (!is_array($value) || array_is_list($value)) {
            throw new ModeContractException('order_policy defined value must be a policy mapping.');
        }
        $this->assertExactKeys($value, ['margin_mode', 'preferred_type'], 'order_policy.value');
        if (!in_array($value['margin_mode'], ['isolated', 'cross'], true) || !in_array($value['preferred_type'], ['limit', 'market'], true)) {
            throw new ModeContractException('order_policy defined value is invalid.');
        }
    }

    /** @param array<string, mixed> $timeframes */
    private function assertTimeframes(array $timeframes): void
    {
        $this->assertExactKeys($timeframes, ['regime', 'context', 'trigger', 'execution'], 'timeframes');
        foreach (array_keys($timeframes) as $role) {
            foreach ($this->stringList($timeframes, $role, false) as $timeframe) {
                if (!in_array($timeframe, self::TIMEFRAMES, true)) {
                    throw new ModeContractException(sprintf('Unsupported timeframe "%s" in role "%s".', $timeframe, $role));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $mapping
     * @param list<string> $keys
     */
    private function assertExactKeys(array $mapping, array $keys, string $path): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $mapping)) {
                throw new ModeContractException(sprintf('Missing required field "%s" at %s.', $key, $path));
            }
        }
        $extra = array_diff(array_keys($mapping), $keys);
        if ($extra !== []) {
            throw new ModeContractException(sprintf('Unknown field "%s" at %s.', reset($extra), $path));
        }
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function mapping(array $mapping, string $key): array
    {
        if (!isset($mapping[$key]) || !is_array($mapping[$key]) || array_is_list($mapping[$key])) {
            throw new ModeContractException(sprintf('Field "%s" must be a mapping.', $key));
        }

        return $mapping[$key];
    }

    /** @param array<string, mixed> $mapping */
    private function assertString(array $mapping, string $key): void
    {
        if (!isset($mapping[$key]) || !is_string($mapping[$key]) || trim($mapping[$key]) === '') {
            throw new ModeContractException(sprintf('Field "%s" must be a non-empty string.', $key));
        }
    }

    /**
     * @param array<string, mixed> $mapping
     * @return list<string>
     */
    private function stringList(array $mapping, string $key, bool $allowEmpty): array
    {
        $value = $mapping[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            throw new ModeContractException(sprintf('Field "%s" must be a non-empty list.', $key));
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new ModeContractException(sprintf('Field "%s" must contain non-empty strings.', $key));
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new ModeContractException(sprintf('Field "%s" must not contain duplicates.', $key));
        }

        return $value;
    }
}
