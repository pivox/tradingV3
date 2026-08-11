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
        'day_trading' => ['1.0.0', '1.1.0'],
        'scalping' => ['1.0.0', '1.1.0'],
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
        $isShadowContract = $document['mode_version'] === '1.1.0'
            && in_array($document['mode_id'], ['day_trading', 'scalping'], true);

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
        if ($isShadowContract) {
            $this->assertShadowHorizon($this->mapping($document, 'horizon'), $document['mode_id']);
            $this->assertShadowSession($this->mapping($document, 'session_policy'));
        } else {
            $this->assertDefinedString($this->mapping($document, 'horizon'), 'horizon');
            $this->assertDefinedString($this->mapping($document, 'session_policy'), 'session_policy');
        }
        $this->assertTimeframes($this->mapping($document, 'timeframes'), $isShadowContract);

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
        $tradeBudget = $this->mapping($risk, 'trade_budget');
        if (!in_array($tradeBudget['unit'], ['percent_equity_per_trade', 'quote_notional'], true)) {
            throw new ModeContractException('risk.trade_budget.unit must be "percent_equity_per_trade" or "quote_notional".');
        }
        $this->assertUnit($this->mapping($risk, 'daily_loss_cap'), 'risk.daily_loss_cap', 'compound_percent_equity_and_quote_per_day');
        $this->assertUnit($this->mapping($risk, 'max_concurrent_positions'), 'risk.max_concurrent_positions', 'positions');
        $this->assertUnit($this->mapping($risk, 'mode_exposure_cap'), 'risk.mode_exposure_cap', 'percent_equity_notional');
        if ($tradeBudget['unit'] === 'quote_notional') {
            $this->assertDefinedQuoteBudget($tradeBudget);
        } else {
            $this->assertDefinedPositiveNumber($tradeBudget, 'risk.trade_budget');
        }
        $this->assertDefinedDailyCap($this->mapping($risk, 'daily_loss_cap'), $isShadowContract);
        if ($isShadowContract) {
            $this->assertShadowConcurrency($this->mapping($risk, 'max_concurrent_positions'), $document['mode_id']);
        } else {
            $this->assertDefinedPositiveInteger($this->mapping($risk, 'max_concurrent_positions'), 'risk.max_concurrent_positions');
        }
        $this->assertDefinedPositiveNumber($this->mapping($risk, 'mode_exposure_cap'), 'risk.mode_exposure_cap');
        $leverage = $this->mapping($document, 'leverage');
        $this->assertDecision($leverage, 'leverage');
        $this->assertUnit($leverage, 'leverage', 'leverage_multiple');
        $this->assertDefinedPositiveNumber($leverage, 'leverage');
        $orderPolicy = $this->mapping($document, 'order_policy');
        $this->assertDecision($orderPolicy, 'order_policy');
        $this->assertUnit($orderPolicy, 'order_policy', 'policy');
        $this->assertDefinedOrderPolicy($orderPolicy, $isShadowContract);

        if ($isShadowContract) {
            $this->assertShadowFrozenValues($document);
        }

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
    private function assertDefinedDailyCap(array $decision, bool $extended = false): void
    {
        if ($decision['state'] !== 'defined') {
            return;
        }
        $value = $decision['value'];
        if (!is_array($value) || array_is_list($value)) {
            throw new ModeContractException('risk.daily_loss_cap defined value must be a cap mapping.');
        }
        $keys = ['percent_equity', 'absolute_quote', 'quote_currency'];
        if ($extended) {
            $keys = [...$keys, 'day_timezone', 'day_boundary_local', 'include_unrealized_loss'];
        }
        $this->assertExactKeys($value, $keys, 'risk.daily_loss_cap.value');
        if ((!is_int($value['percent_equity']) && !is_float($value['percent_equity'])) || $value['percent_equity'] <= 0 || !is_finite((float) $value['percent_equity'])) {
            throw new ModeContractException('risk.daily_loss_cap percent_equity must be positive.');
        }
        if ((!is_int($value['absolute_quote']) && !is_float($value['absolute_quote'])) || $value['absolute_quote'] <= 0 || !is_finite((float) $value['absolute_quote']) || $value['quote_currency'] !== 'USDT') {
            throw new ModeContractException('risk.daily_loss_cap absolute_quote/currency are invalid.');
        }
        if ($extended && ($value['day_timezone'] !== 'UTC' || $value['day_boundary_local'] !== '00:00:00' || $value['include_unrealized_loss'] !== true)) {
            throw new ModeContractException('risk.daily_loss_cap shadow semantics are invalid.');
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDefinedQuoteBudget(array $decision): void
    {
        if ($decision['state'] !== 'defined') {
            return;
        }
        $value = $decision['value'];
        if (!is_array($value) || array_is_list($value)) {
            throw new ModeContractException('risk.trade_budget defined quote value must be a budget mapping.');
        }
        $this->assertExactKeys($value, ['amount', 'quote_currency'], 'risk.trade_budget.value');
        if ((!is_int($value['amount']) && !is_float($value['amount'])) || $value['amount'] <= 0
            || !is_finite((float) $value['amount']) || $value['quote_currency'] !== 'USDT') {
            throw new ModeContractException('risk.trade_budget quote amount/currency are invalid.');
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertDefinedOrderPolicy(array $decision, bool $shadow = false): void
    {
        if ($decision['state'] !== 'defined') {
            return;
        }
        $value = $decision['value'];
        if (!is_array($value) || array_is_list($value)) {
            throw new ModeContractException('order_policy defined value must be a policy mapping.');
        }
        $keys = ['margin_mode', 'preferred_type'];
        if ($shadow) {
            $keys[] = 'market_fallback';
        }
        $this->assertExactKeys($value, $keys, 'order_policy.value');
        if (!in_array($value['margin_mode'], ['isolated', 'cross'], true) || !in_array($value['preferred_type'], ['limit', 'market'], true)) {
            throw new ModeContractException('order_policy defined value is invalid.');
        }
        if ($shadow && $value['market_fallback'] !== false) {
            throw new ModeContractException('order_policy market fallback must remain disabled.');
        }
    }

    /** @param array<string, mixed> $timeframes */
    private function assertTimeframes(array $timeframes, bool $withConfirmations = false): void
    {
        $keys = ['regime', 'context', 'trigger', 'execution'];
        if ($withConfirmations) {
            $keys[] = 'confirmations';
        }
        $this->assertExactKeys($timeframes, $keys, 'timeframes');
        foreach (array_keys($timeframes) as $role) {
            foreach ($this->stringList($timeframes, $role, false) as $timeframe) {
                if (!in_array($timeframe, self::TIMEFRAMES, true)) {
                    throw new ModeContractException(sprintf('Unsupported timeframe "%s" in role "%s".', $timeframe, $role));
                }
            }
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertShadowHorizon(array $decision, string $modeId): void
    {
        $value = $decision['value'] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new ModeContractException(sprintf('%s 1.1.0 horizon must be a mapping.', $modeId));
        }
        $this->assertExactKeys($value, ['maximum_duration', 'daily_boundary_time', 'daily_boundary_timezone', 'close_before_boundary'], 'horizon.value');
        $duration = $modeId === 'day_trading' ? 'PT8H' : 'PT2H';
        if ($value !== ['maximum_duration' => $duration, 'daily_boundary_time' => '00:00:00', 'daily_boundary_timezone' => 'UTC', 'close_before_boundary' => true]) {
            throw new ModeContractException(sprintf('%s 1.1.0 horizon differs from the frozen shadow decision.', $modeId));
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertShadowSession(array $decision): void
    {
        if (($decision['value'] ?? null) !== ['calendar' => 'continuous_crypto', 'timezone' => 'UTC']) {
            throw new ModeContractException('Shadow session differs from the frozen decision.');
        }
    }

    /** @param array<string, mixed> $decision */
    private function assertShadowConcurrency(array $decision, string $modeId): void
    {
        $value = $decision['value'] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new ModeContractException('risk.max_concurrent_positions shadow value must be a mapping.');
        }
        $this->assertExactKeys($value, ['limit', 'include_pending_entries'], 'risk.max_concurrent_positions.value');
        $limit = $modeId === 'day_trading' ? 4 : 3;
        if ($value !== ['limit' => $limit, 'include_pending_entries' => true]) {
            throw new ModeContractException('risk.max_concurrent_positions differs from the frozen shadow decision.');
        }
    }

    /** @param array<string, mixed> $document */
    private function assertShadowFrozenValues(array $document): void
    {
        $frozen = $document['mode_id'] === 'day_trading'
            ? [
                'timeframes' => ['regime' => ['4h'], 'context' => ['1h'], 'trigger' => ['15m'], 'execution' => ['15m'], 'confirmations' => ['5m', '1m']],
                'cadence' => 'PT15M',
                'trade_budget' => 5.0,
                'daily_loss_cap' => ['percent_equity' => 6.0, 'absolute_quote' => 30.0, 'quote_currency' => 'USDT', 'day_timezone' => 'UTC', 'day_boundary_local' => '00:00:00', 'include_unrealized_loss' => true],
                'mode_exposure_cap' => 100.0,
                'leverage' => 2.0,
            ]
            : [
                'timeframes' => ['regime' => ['1h'], 'context' => ['15m'], 'trigger' => ['5m'], 'execution' => ['5m'], 'confirmations' => ['1m']],
                'cadence' => 'PT5M',
                'trade_budget' => 2.0,
                'daily_loss_cap' => ['percent_equity' => 6.0, 'absolute_quote' => 40.0, 'quote_currency' => 'USDT', 'day_timezone' => 'UTC', 'day_boundary_local' => '00:00:00', 'include_unrealized_loss' => true],
                'mode_exposure_cap' => 75.0,
                'leverage' => 3.0,
            ];

        if ($document['lifecycle']['status'] !== 'shadow' || $document['lifecycle']['executable'] !== true
            || $document['timeframes'] !== $frozen['timeframes']
            || $document['cadence']['evaluation']['value'] !== $frozen['cadence']
            || $document['cadence']['validity_window']['value'] !== $frozen['cadence']
            || $document['risk']['trade_budget']['value'] !== $frozen['trade_budget']
            || $document['risk']['daily_loss_cap']['value'] !== $frozen['daily_loss_cap']
            || $document['risk']['mode_exposure_cap']['value'] !== $frozen['mode_exposure_cap']
            || $document['leverage']['value'] !== $frozen['leverage']
            || $document['order_policy']['value'] !== ['margin_mode' => 'isolated', 'preferred_type' => 'limit', 'market_fallback' => false]) {
            throw new ModeContractException(sprintf('%s 1.1.0 differs from the frozen shadow contract.', $document['mode_id']));
        }

        if ($document['mode_id'] === 'scalping') {
            $this->assertScalpingShadowDataAndMetadata($document);
        }
    }

    /** @param array<string, mixed> $document */
    private function assertScalpingShadowDataAndMetadata(array $document): void
    {
        if ($document['data_contract'] !== [
            'required_inputs' => [
                ['kind' => 'candles', 'timeframes' => ['1h', '15m', '5m', '1m'], 'fields' => ['open', 'high', 'low', 'close', 'volume']],
                ['kind' => 'order_book', 'timeframes' => ['5m'], 'fields' => ['best_bid', 'best_ask', 'spread_bps']],
            ],
            'missing_data_policy' => 'reject',
        ]) {
            throw new ModeContractException('scalping 1.1.0 data contract differs from the frozen shadow contract.');
        }

        $decisions = [
            'horizon' => $document['horizon'],
            'session_policy' => $document['session_policy'],
            'cadence.evaluation' => $document['cadence']['evaluation'],
            'cadence.validity_window' => $document['cadence']['validity_window'],
            'risk.trade_budget' => $document['risk']['trade_budget'],
            'risk.daily_loss_cap' => $document['risk']['daily_loss_cap'],
            'risk.max_concurrent_positions' => $document['risk']['max_concurrent_positions'],
            'risk.mode_exposure_cap' => $document['risk']['mode_exposure_cap'],
            'leverage' => $document['leverage'],
            'order_policy' => $document['order_policy'],
        ];
        $expectedMetadata = [
            'horizon' => ['GitHub issue #307 approved decision 2026-08-11', 'Each position is limited to two hours and must be closed before the UTC daily boundary.'],
            'session_policy' => ['GitHub issue #307 approved decision 2026-08-11', 'Shadow evaluation is continuous for crypto while all daily accounting is in UTC.'],
            'cadence.evaluation' => ['GitHub issue #307 approved decision 2026-08-11', 'The immutable shadow contract evaluates on the five-minute execution cadence.'],
            'cadence.validity_window' => ['GitHub issue #307 approved decision 2026-08-11', 'A decision expires at the next five-minute evaluation boundary.'],
            'risk.trade_budget' => ['config/app/trade_entry.scalper.yaml:73-78 pinned by #307', 'The lower explicit fixed-risk authority replaces the conflicting 7-percent request.'],
            'risk.daily_loss_cap' => ['GitHub issue #307 approved decision 2026-08-11', 'The lower of six percent equity and 40 USDT applies in UTC to realized and unrealized loss.'],
            'risk.max_concurrent_positions' => ['GitHub issue #307 approved decision 2026-08-11', 'Three positions is the cap and pending entries reserve a concurrency slot before filling.'],
            'risk.mode_exposure_cap' => ['GitHub issue #307 approved decision 2026-08-11', 'Aggregate scalping notional is capped at 75 percent of equity.'],
            'leverage' => ['config/trading/mode_exchange/scalper.{okx,hyperliquid}.yaml pinned by #307', 'Conservative venue envelope copied without legacy runtime loading.'],
            'order_policy' => ['GitHub issue #307 approved decision 2026-08-11', 'Only isolated limit orders are admissible; market fallback is prohibited.'],
        ];
        foreach ($expectedMetadata as $path => [$source, $justification]) {
            if ($decisions[$path]['source'] !== $source || $decisions[$path]['justification'] !== $justification) {
                throw new ModeContractException(sprintf('scalping 1.1.0 %s metadata differs from the frozen shadow contract.', $path));
            }
        }

        if ($document['provenance'] !== [
            ['path' => 'horizon', 'source' => 'GitHub issue #307 approved decision 2026-08-11', 'unit' => 'holding_horizon_policy', 'justification' => 'PT2H maximum duration and a 00:00 UTC close boundary are explicit decisions.'],
            ['path' => 'session_policy', 'source' => 'GitHub issue #307 approved decision 2026-08-11', 'unit' => 'session_policy', 'justification' => 'Continuous crypto session with UTC accounting is explicit.'],
            ['path' => 'timeframes', 'source' => 'GitHub issue #307 approved decision 2026-08-11', 'unit' => 'timeframe_roles', 'justification' => '1h/15m/5m/5m/1m are the approved regime, context, trigger, execution and confirmation roles.'],
            ['path' => 'cadence', 'source' => 'GitHub issue #307 approved decision 2026-08-11', 'unit' => 'duration', 'justification' => 'Evaluation and validity are both fixed at PT5M.'],
            ['path' => 'risk', 'source' => 'config/app/trade_entry.scalper.yaml:73-78 pinned by #307', 'unit' => 'canonical_risk_policy', 'justification' => 'The lower explicit fixed-risk authority replaces the conflicting 7-percent request.'],
            ['path' => 'leverage', 'source' => 'config/trading/mode_exchange/scalper.{okx,hyperliquid}.yaml pinned by #307', 'unit' => 'leverage_multiple', 'justification' => 'Conservative venue envelope copied without legacy runtime loading.'],
            ['path' => 'order_policy', 'source' => 'GitHub issue #307 approved decision 2026-08-11', 'unit' => 'policy', 'justification' => 'Isolated limit intent has no market fallback.'],
            ['path' => 'compatible_setup_ids', 'source' => 'GitHub issue #300 catalog for #301', 'unit' => 'setup_id', 'justification' => 'The immutable contract uses the existing three-setup scalping catalog.'],
            ['path' => 'data_contract.required_inputs', 'source' => 'GitHub issue #307 approved decision 2026-08-11', 'unit' => 'required_market_data', 'justification' => 'Candles for all approved timeframes and a five-minute order book are fail-closed inputs.'],
        ]) {
            throw new ModeContractException('scalping 1.1.0 decision metadata differs from the frozen shadow contract.');
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
