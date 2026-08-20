<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Rules\Catalog\ConditionCatalogException;
use App\TradingCore\Rules\Catalog\ConditionCatalogResolver;
use App\TradingCore\Setup\Exception\SetupContractException;

final class SetupContractValidator
{
    public const PROVENANCE_PATHS = [
        'mode_compatibility', 'context.regime', 'context.context', 'context.trigger', 'context.confirmations',
        'filters', 'filters.lev_bounds', 'no_trade_rules',
        'execution.entry_zone', 'execution.stop', 'execution.targets', 'execution.minimum_net_r',
        'execution.invalidation', 'execution.time_stop', 'validity_window',
        'execution.cost_contract', 'execution.order_policy', 'execution.execution_timeframe',
        'execution.mandatory_confirmations', 'execution.risk_boundary', 'legacy.retest_variant',
    ];
    public const SETUP_IDS = [
        'day_trading.trend_continuation.long', 'day_trading.trend_continuation.short',
        'scalping.trend_continuation.long', 'scalping.pullback.long', 'scalping.trend_momentum.short',
        'micro_scalping.momentum_ofi.long', 'micro_scalping.momentum_ofi.short', 'crash_short',
    ];
    private const TIMEFRAMES = ['4h', '1h', '15m', '5m', '1m', 'global'];
    private const STATUSES = ['draft', 'blocked', 'shadow', 'paper', 'candidate', 'active', 'retired'];
    private const CRASH_DECISION_SOURCE_ORIGINS = [
        [
            'file' => 'src/MtfValidator/config/validations.crash.yaml',
            'line_range' => '5-16,136-137,164-167,169-305',
            'content_sha256' => '5dd5cbf03cdbcb804cd664e47c0dce4007438bbce973af027a05e7155b2c10e2',
            'commit' => 'd1d9a174960660e88f84c54850ef61181d39a880',
        ],
        [
            'file' => 'config/app/trade_entry.crash.yaml',
            'line_range' => '7-12,19-205',
            'content_sha256' => '722bd2ee013a24ae86ffae2aa846437db7a51898ef8de4a0cd58e693a8ffb90f',
            'commit' => '6ff8ab88e1bb9465f92f39424ae64305ca20ee0d',
        ],
    ];
    private const SCALPING_SHADOW_SOURCE_ORIGINS = [
        'scalping.trend_continuation.long' => [
            'file' => 'src/MtfValidator/config/validations.scalper.yaml',
            'line_range' => '6-14,216-356',
            'content_sha256' => '5bf86ce415079ee896a98d2c91e987d11db975c986500862b0cff82440c590a2',
            'commit' => '6c42d14d20798f6fee9d55b306ccaa0539af5e79',
        ],
        'scalping.pullback.long' => [
            'file' => 'src/MtfValidator/config/validations.scalper.yaml',
            'line_range' => '6-14,157-161,216-356',
            'content_sha256' => '5bf86ce415079ee896a98d2c91e987d11db975c986500862b0cff82440c590a2',
            'commit' => '6c42d14d20798f6fee9d55b306ccaa0539af5e79',
        ],
        'scalping.trend_momentum.short' => [
            'file' => 'src/MtfValidator/config/validations.scalper.yaml',
            'line_range' => '6-14,216-356',
            'content_sha256' => '5bf86ce415079ee896a98d2c91e987d11db975c986500862b0cff82440c590a2',
            'commit' => '6c42d14d20798f6fee9d55b306ccaa0539af5e79',
        ],
    ];
    private const MICRO_SCALPING_SHADOW_SOURCE_ORIGINS = [
        'micro_scalping.momentum_ofi.long' => [
            'file' => 'src/MtfValidator/config/validations.scalper_micro.yaml',
            'line_range' => '5-19,87-115',
            'content_sha256' => '47969bd5055b28ba5871b0b22e503482730a368c56fad3d0963aaad3808808e2',
            'commit' => '75a4cef8852f99d6e8202422fdd0531cdfce60bb',
        ],
        'micro_scalping.momentum_ofi.short' => [
            'file' => 'src/MtfValidator/config/validations.scalper_micro.yaml',
            'line_range' => '5-19,95-125',
            'content_sha256' => '47969bd5055b28ba5871b0b22e503482730a368c56fad3d0963aaad3808808e2',
            'commit' => '75a4cef8852f99d6e8202422fdd0531cdfce60bb',
        ],
    ];
    /** Canonical documents are explicit in setup-contract.schema.json scalping* $defs. */
    private const SCALPING_SHADOW_DOCUMENT_HASHES = [
        'scalping.trend_continuation.long' => '7ebaae24166be3a248a39b66bb545c52b7eb4498f9c0419bb2dcf919de23136e',
        'scalping.pullback.long' => 'a02681c73c81609d4cef23b88c7e3acce22b06837f3573c90c113c8d979794ed',
        'scalping.trend_momentum.short' => '66d12bb65c67b44126a865abc67d8f716320c6c557d33a6a14c75695ca99f769',
    ];
    private const MICRO_SCALPING_SHADOW_DOCUMENT_HASHES = [
        'micro_scalping.momentum_ofi.long' => '3d9361dd5fb6357786ee2c193a2f19ccb88ee875c7e6e14c0d12672e3ebd003d',
        'micro_scalping.momentum_ofi.short' => '392e735295e8d2529479556cee036f27f052fca8f18b73fb87848398edd5a672',
    ];
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

    private ConditionCatalog $conditionCatalog;

    public function __construct(private readonly ?ConditionCatalog $suppliedConditionCatalog = null)
    {
    }

    /** @param array<string, mixed> $document */
    public function validate(array $document): void
    {
        $isCrashDecision = ($document['setup_id'] ?? null) === 'crash_short' && ($document['setup_version'] ?? null) === '1.1.0';
        $isDayTradingLongShadow = ($document['setup_id'] ?? null) === 'day_trading.trend_continuation.long'
            && ($document['setup_version'] ?? null) === '1.1.0';
        $isScalpingShadow = in_array($document['setup_id'] ?? null, [
            'scalping.trend_continuation.long',
            'scalping.pullback.long',
            'scalping.trend_momentum.short',
        ], true) && ($document['setup_version'] ?? null) === '1.1.0';
        $isMicroScalpingShadow = in_array($document['setup_id'] ?? null, [
            'micro_scalping.momentum_ofi.long',
            'micro_scalping.momentum_ofi.short',
        ], true) && ($document['setup_version'] ?? null) === '1.1.0';
        $isExecutableShadow = $isDayTradingLongShadow || $isScalpingShadow || $isMicroScalpingShadow;
        $topKeys = self::TOP_KEYS;
        if ($isCrashDecision) {
            $topKeys = array_values(array_diff($topKeys, ['source_origin']));
            $topKeys[] = 'source_origins';
        }
        $this->exact($document, $topKeys, 'contract');
        foreach (['schema_version', 'setup_id', 'setup_version', 'status', 'family', 'side', 'thesis', 'hypothesis', 'ownership_model'] as $key) {
            $this->string($document, $key, 'contract');
        }
        $publishedAtOneOne = [
            'crash_short',
            'day_trading.trend_continuation.long',
            'scalping.trend_continuation.long',
            'scalping.pullback.long',
            'scalping.trend_momentum.short',
            'micro_scalping.momentum_ofi.long',
            'micro_scalping.momentum_ofi.short',
        ];
        if ($document['schema_version'] !== '1.0.0'
            || ($document['setup_version'] !== '1.0.0'
                && ($document['setup_version'] !== '1.1.0' || !in_array($document['setup_id'], $publishedAtOneOne, true)))) {
            throw new SetupContractException('Only exact published setup versions are accepted; aliases and ranges are forbidden.');
        }
        try {
            $this->conditionCatalog = (new ConditionCatalogResolver())->forSetupDocument(
                $document,
                $this->suppliedConditionCatalog,
            );
        } catch (ConditionCatalogException $exception) {
            throw new SetupContractException($exception->getMessage(), previous: $exception);
        }
        if (!isset(self::EXPECTED[$document['setup_id']])) {
            throw new SetupContractException(sprintf('Unknown canonical setup id "%s".', $document['setup_id']));
        }
        [$status, $side, $mode] = self::EXPECTED[$document['setup_id']];
        if ($isCrashDecision) {
            $status = 'blocked';
        }
        if ($isExecutableShadow) {
            $status = 'shadow';
        }
        if (!in_array($document['status'], self::STATUSES, true) || $document['status'] !== $status || $document['side'] !== $side) {
            throw new SetupContractException('Setup identity, initial status, or side differs from the frozen catalog.');
        }
        if (!is_bool($document['executable'])
            || ($isExecutableShadow ? $document['executable'] !== true : $document['executable'] !== false)
            || ($isExecutableShadow ? $document['status'] !== 'shadow' : !in_array($document['status'], ['draft', 'blocked'], true))) {
            throw new SetupContractException('Setup executable state differs from its exact published lifecycle.');
        }
        if ($document['ownership_model'] !== 'setup-contract-ownership-v1') {
            throw new SetupContractException('Unknown setup ownership model.');
        }

        $origins = $isCrashDecision
            ? $this->list($document, 'source_origins', false, 'contract')
            : [$this->map($document, 'source_origin', 'contract')];
        $sourceFiles = [];
        foreach ($origins as $index => $origin) {
            if (!is_array($origin) || array_is_list($origin)) {
                throw new SetupContractException(sprintf('source_origins[%d] must be a mapping.', $index));
            }
            $this->sourceOrigin($origin, $isCrashDecision ? sprintf('source_origins[%d]', $index) : 'source_origin');
            if (isset($sourceFiles[$origin['file']])) {
                throw new SetupContractException(sprintf('Duplicate source origin file "%s".', $origin['file']));
            }
            $sourceFiles[$origin['file']] = true;
        }
        if ($isCrashDecision && $origins !== self::CRASH_DECISION_SOURCE_ORIGINS) {
            throw new SetupContractException('crash_short 1.1.0 source origins must match the exact #310 source pins.');
        }
        if ($isScalpingShadow && $origins !== [self::SCALPING_SHADOW_SOURCE_ORIGINS[$document['setup_id']]]) {
            throw new SetupContractException('scalping shadow source origin must match the exact #307 source pin.');
        }
        if ($isMicroScalpingShadow && $origins !== [self::MICRO_SCALPING_SHADOW_SOURCE_ORIGINS[$document['setup_id']]]) {
            throw new SetupContractException('micro-scalping shadow source origin must match the exact #308 source pin.');
        }

        $modes = $this->list($document, 'compatible_modes', true, 'contract');
        foreach ($modes as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new SetupContractException('compatible_modes entries must be mappings.');
            }
            $this->exact($row, ['mode_id', 'mode_version'], 'compatible_modes[]');
            $expectedModeVersion = $isExecutableShadow ? '1.1.0' : '1.0.0';
            if (!in_array($row['mode_id'] ?? null, ['day_trading', 'scalping', 'micro_scalping'], true) || ($row['mode_version'] ?? null) !== $expectedModeVersion) {
                throw new SetupContractException('Compatible modes must reference the frozen #300 modern catalog and exact versions.');
            }
        }
        $expectedModeVersion = $isExecutableShadow ? '1.1.0' : '1.0.0';
        if ($mode === null ? $modes !== [] : $modes !== [['mode_id' => $mode, 'mode_version' => $expectedModeVersion]]) {
            throw new SetupContractException('Setup/mode compatibility differs from the frozen #300 catalog; crash is the sole unresolved exception.');
        }
        $compatibility = $this->map($document, 'mode_compatibility', 'contract');
        $this->exact($compatibility, ['state', 'issue', 'justification'], 'mode_compatibility');
        $expectedCrashCompatibility = $isCrashDecision ? 'distinct_operational_envelope' : 'unresolved';
        if ($mode === null && ($compatibility['state'] !== $expectedCrashCompatibility || $compatibility['issue'] !== '#310')) {
            throw new SetupContractException('crash_short mode compatibility must match its exact versioned #310 decision.');
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
        $executionKeys = ['side', 'entry_zone', 'stop', 'targets', 'minimum_net_r', 'invalidation', 'time_stop', 'cost_contract'];
        if ($isExecutableShadow) {
            $executionKeys = ['side', 'execution_timeframe', 'mandatory_confirmations', ...array_slice($executionKeys, 1), 'order_policy'];
        }
        if ($isCrashDecision) {
            $executionKeys[] = 'order_policy';
            $executionKeys[] = 'risk_boundary';
        }
        $this->exact($execution, $executionKeys, 'execution');
        foreach (['entry_zone', 'stop', 'targets', 'minimum_net_r', 'invalidation', 'time_stop', 'cost_contract'] as $key) {
            $this->decision($this->map($execution, $key, 'execution'), 'execution.' . $key, $isCrashDecision && $key === 'cost_contract');
        }
        if ($isExecutableShadow) {
            foreach (['execution_timeframe', 'mandatory_confirmations', 'order_policy'] as $key) {
                $this->decision($this->map($execution, $key, 'execution'), 'execution.' . $key);
            }
            $this->assertFrozenShadowExecution($execution, $isMicroScalpingShadow ? 'micro_scalping' : ($isScalpingShadow ? 'scalping' : 'day_trading'));
        }
        if ($isCrashDecision) {
            foreach (['order_policy', 'risk_boundary'] as $key) {
                $this->decision($this->map($execution, $key, 'execution'), 'execution.' . $key, true);
            }
        }
        $this->decision($this->map($document, 'validity_window', 'contract'), 'validity_window');
        if ($isDayTradingLongShadow && $document['validity_window']['value'] !== 'PT15M') {
            throw new SetupContractException('day_trading long shadow validity window must be PT15M.');
        }
        if ($isScalpingShadow && $document['validity_window']['value'] !== 'PT5M') {
            throw new SetupContractException('scalping shadow validity window must be PT5M.');
        }
        if ($isMicroScalpingShadow && $document['validity_window']['value'] !== 'PT5S') {
            throw new SetupContractException('micro-scalping shadow validity window must be PT5S.');
        }

        $data = $this->map($document, 'data_condition_contract', 'contract');
        $dataKeys = ['required_data', 'missing_conditions', 'external_dependencies', 'condition_catalog_hash', 'unknown_condition_policy'];
        if ($isScalpingShadow || $isMicroScalpingShadow) {
            array_unshift($dataKeys, 'condition_catalog_version');
        }
        $this->exact($data, $dataKeys, 'data_condition_contract');
        $requiredData = $this->strings($this->list($data, 'required_data', false, 'data_condition_contract'), 'required_data');
        $this->assertUniqueStrings($requiredData, 'data_condition_contract.required_data');
        $missing = $this->strings($this->list($data, 'missing_conditions', true, 'data_condition_contract'), 'missing_conditions');
        $this->assertUniqueStrings($missing, 'data_condition_contract.missing_conditions');
        foreach ($missing as $condition) {
            if (!in_array($condition, $this->conditionCatalog->conditionIds(), true)) {
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
        if ($isScalpingShadow) {
            $expectedRequiredData = [
                'ohlcv_1h', 'ohlcv_15m', 'ohlcv_5m', 'ohlcv_1m', 'ema', 'macd', 'rsi', 'atr',
                'vwap', 'volume_ratio', 'order_book', 'fee_schedule', 'funding_schedule',
            ];
            if ($requiredData !== $expectedRequiredData || $missing !== [] || $data['external_dependencies'] !== []) {
                throw new SetupContractException('scalping shadow data requirements differ from the frozen executable contract.');
            }
            if ($conditionCatalogHash['state'] !== 'defined' || $conditionCatalogHash['value'] !== $this->conditionCatalog->stableHash()) {
                throw new SetupContractException('scalping shadow must pin the exact canonical condition catalog hash.');
            }
            if ($data['condition_catalog_version'] !== $this->conditionCatalog->catalogVersion) {
                throw new SetupContractException('scalping shadow must pin condition catalog version 1.1.0.');
            }
        }
        if ($isMicroScalpingShadow) {
            $expectedRequiredData = [
                'ohlcv_5m', 'ohlcv_1m', 'macd', 'rsi', 'atr', 'vwap', 'authenticated_order_book',
                'authenticated_public_trades', 'fee_schedule', 'funding_schedule',
            ];
            if ($requiredData !== $expectedRequiredData || $missing !== [] || $data['external_dependencies'] !== []) {
                throw new SetupContractException('micro-scalping shadow data requirements differ from the frozen executable contract.');
            }
            if ($conditionCatalogHash['state'] !== 'defined' || $conditionCatalogHash['value'] !== $this->conditionCatalog->stableHash()) {
                throw new SetupContractException('micro-scalping shadow must pin the exact canonical condition catalog hash.');
            }
            if ($data['condition_catalog_version'] !== '1.2.0' || $data['condition_catalog_version'] !== $this->conditionCatalog->catalogVersion) {
                throw new SetupContractException('micro-scalping shadow must pin condition catalog version 1.2.0.');
            }
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
        if (($isScalpingShadow || $isMicroScalpingShadow) && $knownDefects !== []) {
            throw new SetupContractException('Executable shadow known_defects must be empty.');
        }
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
        if ($isScalpingShadow) {
            $actualHash = $this->semanticDocumentHash($document);
            $expectedHash = self::SCALPING_SHADOW_DOCUMENT_HASHES[$document['setup_id']];
            if (!hash_equals($expectedHash, $actualHash)) {
                throw new SetupContractException(sprintf(
                    '%s@1.1.0 differs from its exact frozen proof document.',
                    $document['setup_id'],
                ));
            }
        }
        if ($isMicroScalpingShadow) {
            $actualHash = $this->semanticDocumentHash($document);
            $expectedHash = self::MICRO_SCALPING_SHADOW_DOCUMENT_HASHES[$document['setup_id']];
            if (!hash_equals($expectedHash, $actualHash)) {
                throw new SetupContractException(sprintf(
                    '%s@1.1.0 differs from its exact frozen proof document.',
                    $document['setup_id'],
                ));
            }
        }
    }

    /** @param array<string, mixed> $execution */
    private function assertFrozenShadowExecution(array $execution, string $modeId): void
    {
        $stopTimeframe = $modeId === 'micro_scalping' ? '1m' : '5m';
        $common = [
            'stop' => ['kind' => 'atr', 'timeframe' => $stopTimeframe, 'atr_multiplier' => 1.5, 'pivot_id' => null, 'buffer_rate' => 0.0],
            'minimum_net_r' => 1.3,
            'invalidation' => ['kind' => 'close_beyond_stop'],
            'cost_contract' => ['entry_liquidity_role' => 'maker', 'stop_liquidity_role' => 'taker', 'entry_spread_source' => 'order_book', 'entry_slippage_source' => 'execution_model', 'stop_spread_source' => 'order_book', 'stop_slippage_source' => 'execution_model', 'target_spread_source' => 'order_book', 'target_slippage_source' => 'execution_model', 'funding_source' => 'venue_schedule', 'funding_interval_seconds' => 28800],
        ];
        $expected = match ($modeId) {
            'scalping' => [
            'execution_timeframe' => '5m',
            'mandatory_confirmations' => ['1m'],
            'entry_zone' => ['anchor_source' => 'vwap', 'anchor_timeframe' => '5m', 'atr_timeframe' => '5m', 'atr_multiplier' => 0.22, 'minimum_half_width_rate' => 0.0004, 'maximum_half_width_rate' => 0.0065, 'asymmetry_rate' => 0.0, 'ttl_seconds' => 150, 'maximum_input_age_seconds' => 30, 'quantize_outward' => true],
            ...$common,
            'targets' => [['id' => 'tp1', 'risk_multiple' => 1.8, 'liquidity_role' => 'taker']],
            'time_stop' => 'PT2H',
            'order_policy' => ['type' => 'limit', 'liquidity_role' => 'maker', 'ttl_seconds' => 45, 'cancel_after_seconds' => 75, 'market_fallback' => false, 'maximum_spread_bps' => 6.0, 'maximum_slippage_bps' => 8.0],
            ],
            'micro_scalping' => [
                'execution_timeframe' => '1m',
                'mandatory_confirmations' => ['1m'],
                'entry_zone' => ['anchor_source' => 'vwap', 'anchor_timeframe' => '1m', 'atr_timeframe' => '1m', 'atr_multiplier' => 0.35, 'minimum_half_width_rate' => 0.0005, 'maximum_half_width_rate' => 0.003, 'asymmetry_rate' => 0.0, 'ttl_seconds' => 30, 'maximum_input_age_seconds' => 5, 'quantize_outward' => true],
                ...$common,
                'targets' => [['id' => 'tp1', 'risk_multiple' => 1.8, 'liquidity_role' => 'taker']],
                'time_stop' => 'PT30M',
                'order_policy' => ['type' => 'limit', 'liquidity_role' => 'maker', 'ttl_seconds' => 30, 'cancel_after_seconds' => 60, 'market_fallback' => false, 'maximum_spread_bps' => 8.0, 'maximum_slippage_bps' => 8.0],
            ],
            'day_trading' => [
            'execution_timeframe' => '15m',
            'mandatory_confirmations' => ['5m', '1m'],
            'entry_zone' => ['anchor_source' => 'vwap', 'anchor_timeframe' => '5m', 'atr_timeframe' => '5m', 'atr_multiplier' => 0.30, 'minimum_half_width_rate' => 0.0005, 'maximum_half_width_rate' => 0.0100, 'asymmetry_rate' => 0.0, 'ttl_seconds' => 240, 'maximum_input_age_seconds' => 60, 'quantize_outward' => true],
            ...$common,
            'targets' => [['id' => 'tp1', 'risk_multiple' => 2.0, 'liquidity_role' => 'taker']],
            'time_stop' => 'PT8H',
            'order_policy' => ['type' => 'limit', 'liquidity_role' => 'maker', 'ttl_seconds' => 90, 'cancel_after_seconds' => 120, 'market_fallback' => false, 'maximum_spread_bps' => 6.0, 'maximum_slippage_bps' => 8.0],
            ],
            default => throw new SetupContractException(sprintf('Unsupported executable mode "%s".', $modeId)),
        };
        foreach ($expected as $key => $value) {
            if (($execution[$key]['state'] ?? null) !== 'defined' || !$this->valuesEquivalent($execution[$key]['value'] ?? null, $value)) {
                throw new SetupContractException(sprintf('%s shadow execution.%s differs from the frozen decision.', $modeId, $key));
            }
        }
    }

    private function valuesEquivalent(mixed $actual, mixed $expected): bool
    {
        if ((is_int($actual) || is_float($actual)) && (is_int($expected) || is_float($expected))) {
            return $this->numbersEquivalent($actual, $expected);
        }
        if (!is_array($actual) || !is_array($expected)) {
            return $actual === $expected;
        }
        if (array_keys($actual) !== array_keys($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (!$this->valuesEquivalent($actual[$key], $value)) {
                return false;
            }
        }

        return true;
    }

    private function numbersEquivalent(int|float $actual, int|float $expected): bool
    {
        if (is_int($actual) && is_int($expected)) {
            return $actual === $expected;
        }
        if (is_float($actual) && is_float($expected)) {
            return is_finite($actual) && is_finite($expected) && $actual === $expected;
        }

        $integer = is_int($actual) ? $actual : $expected;
        $float = is_float($actual) ? $actual : $expected;
        if (!is_finite($float) || floor($float) !== $float) {
            return false;
        }
        $roundTrippedInteger = (int) $float;

        return (float) $roundTrippedInteger === $float && $integer === $roundTrippedInteger;
    }

    /** @param array<string, mixed> $document */
    private function semanticDocumentHash(array $document): string
    {
        try {
            return hash('sha256', json_encode(
                $this->normalizeSemanticNumbers($document),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
            ));
        } catch (\JsonException $exception) {
            throw new SetupContractException('Frozen setup document must be finite and JSON serializable.', 0, $exception);
        }
    }

    private function normalizeSemanticNumbers(mixed $value): mixed
    {
        if (is_int($value)) {
            return $this->canonicalNumericTag('integer', (string) $value);
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new SetupContractException('Frozen setup numeric values must be finite.');
            }
            if ($value === 0.0) {
                return $this->canonicalNumericTag('integer', '0');
            }
            if (floor($value) === $value) {
                $integer = (int) $value;
                if ((float) $integer === $value) {
                    return $this->canonicalNumericTag('integer', (string) $integer);
                }
            }

            return $this->canonicalNumericTag('binary64', $this->canonicalFloatDecimal($value));
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->normalizeSemanticNumbers(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeSemanticNumbers($item);
        }

        return $value;
    }

    /** @return array{__setup_contract_number__: array{kind: string, decimal: string}} */
    private function canonicalNumericTag(string $kind, string $decimal): array
    {
        return ['__setup_contract_number__' => ['kind' => $kind, 'decimal' => $decimal]];
    }

    private function canonicalFloatDecimal(float $value): string
    {
        $previousPrecision = ini_get('serialize_precision');
        if ($previousPrecision === false) {
            throw new SetupContractException('Frozen setup numeric values need a stable decimal serializer.');
        }
        $changed = $previousPrecision !== '-1';
        if ($changed && ini_set('serialize_precision', '-1') === false) {
            throw new SetupContractException('Frozen setup numeric values need a stable decimal serializer.');
        }

        $encoded = false;
        $restored = true;
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $exception) {
            throw new SetupContractException('Frozen setup numeric values need a stable decimal serializer.', 0, $exception);
        } finally {
            if ($changed) {
                $restored = ini_set('serialize_precision', $previousPrecision) !== false;
            }
        }
        if (!is_string($encoded) || !$restored) {
            throw new SetupContractException('Frozen setup numeric values need a stable decimal serializer.');
        }

        return $encoded;
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
        if (!in_array($condition, $this->conditionCatalog->conditionIds(), true)) {
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
        $parameterDefinitions = $this->conditionCatalog->definition($condition)->parameters;
        $allowed = array_keys($parameterDefinitions);
        $extra = array_diff(array_keys($parameters), $allowed);
        if ($extra !== []) {
            throw new SetupContractException(sprintf('Unknown parameter "%s" for condition "%s".', reset($extra), $condition));
        }
        foreach ($parameters as $key => $value) {
            $type = $parameterDefinitions[$key]->type;
            $valid = match ($type) {
                'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
                'integer' => is_int($value),
                'string' => is_string($value) && trim($value) !== '',
                'boolean' => is_bool($value),
                default => false,
            };
            if (!$valid) {
                throw new SetupContractException(sprintf('Parameter "%s" for condition "%s" must be a finite %s.', $key, $condition, $type));
            }
        }
    }

    /** @param array<string, mixed> $decision */
    private function decision(array $decision, string $path, bool $requiresUnknownPolicy = false): void
    {
        $keys = ['state', 'value', 'unit', 'source', 'justification'];
        if ($requiresUnknownPolicy) {
            $keys[] = 'unknown_policy';
        }
        $this->exact($decision, $keys, $path);
        if (!in_array($decision['state'] ?? null, ['defined', 'unresolved'], true)) {
            throw new SetupContractException($path . '.state must be defined or unresolved.');
        }
        foreach (['unit', 'source', 'justification'] as $key) {
            $this->string($decision, $key, $path);
        }
        if ($requiresUnknownPolicy && (
            $decision['state'] !== 'unresolved'
            || $decision['value'] !== null
            || ($decision['unknown_policy'] ?? null) !== 'reject'
        )) {
            throw new SetupContractException($path . ' must remain unresolved with null value and reject unknown inputs.');
        }
        if (($decision['state'] === 'unresolved') !== ($decision['value'] === null)) {
            throw new SetupContractException($path . ' state/value mismatch.');
        }
    }

    /** @param array<string, mixed> $origin */
    private function sourceOrigin(array $origin, string $path): void
    {
        $this->exact($origin, ['file', 'line_range', 'content_sha256', 'commit'], $path);
        foreach (['file', 'line_range', 'content_sha256', 'commit'] as $key) {
            $this->string($origin, $key, $path);
        }
        if (preg_match('/^[a-f0-9]{64}$/', $origin['content_sha256']) !== 1 || preg_match('/^[a-f0-9]{40}$/', $origin['commit']) !== 1) {
            throw new SetupContractException('Source origin hashes must be exact immutable SHA values.');
        }
        if (preg_match('/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/', $origin['line_range']) !== 1) {
            throw new SetupContractException($path . '.line_range has invalid grammar.');
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
