<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use App\TradingCore\Config\Exception\TradingConfigException;

final class EffectiveTradingConfigComposer
{
    private const ORDER = ['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'];
    private const OVERRIDABLE_PATHS = [
        'mode.risk.trade_budget.value',
        'mode.risk.daily_loss_cap.value',
        'mode.risk.max_concurrent_positions.value',
        'mode.risk.mode_exposure_cap.value',
        'mode.leverage.value',
        'mode.order_policy.value',
        'exchange.fees.maker_rate',
        'exchange.fees.taker_rate',
        'exchange.funding.interval',
    ];

    /** @param list<TradingConfigLayer> $layers */
    public function compose(EffectiveTradingConfigRequest $request, array $layers, ?string $conditionCatalogHash): EffectiveTradingConfigSnapshot
    {
        if (count($layers) !== count(self::ORDER)) {
            throw new TradingConfigException('Effective runtime resolution requires all six required layers; optional layers and fallback are forbidden.');
        }
        $actualOrder = array_column($layers, 'type');
        if ($actualOrder !== self::ORDER) {
            throw new TradingConfigException('Invalid effective config layer order; expected base < mode < setup < exchange < mode_exchange < environment.');
        }
        foreach ($layers as $layer) {
            if (!$layer->required) {
                throw new TradingConfigException(sprintf('Runtime layer "%s" must be required.', $layer->type));
            }
            $this->assertOwnedDocument($layer);
        }
        $this->assertCompiledSetup($request, $layers[2], $conditionCatalogHash);

        $payload = [];
        $provenance = [];
        foreach (array_slice($layers, 0, 4) as $layer) {
            foreach ($layer->config as $key => $value) {
                if (array_key_exists($key, $payload)) {
                    throw new TradingConfigException(sprintf('Path "%s" is double-owned by layer "%s".', $key, $layer->type));
                }
                $payload[$key] = $this->normalize($value, $key);
                $this->recordLeaves($provenance, $key, $value, $layer->toLogContext());
            }
        }

        $pair = $layers[4];
        $this->assertPairIdentity($request, $pair->config);
        /** @var array<string,mixed> $overrides */
        $overrides = $pair->config['overrides'];
        foreach ($overrides as $path => $value) {
            if (!in_array($path, self::OVERRIDABLE_PATHS, true)) {
                throw new TradingConfigException(sprintf('mode_exchange override path "%s" is not explicitly allowed.', $path));
            }
            $current = $this->readPath($payload, $path);
            if (!$this->hasCompatibleOverrideType($path, $current, $value)) {
                throw new TradingConfigException(sprintf('mode_exchange override type mismatch at "%s".', $path));
            }
            $this->assertOverrideValue($path, $current, $value);
            $this->writePath($payload, $path, $this->normalize($value, $path));
            $this->clearProvenance($provenance, $path);
            $this->recordLeaves($provenance, $path, $value, $pair->toLogContext());
        }

        $environment = $layers[5];
        $payload['environment'] = $this->normalize($environment->config['environment'], 'environment');
        $this->recordLeaves($provenance, 'environment', $environment->config['environment'], $environment->toLogContext());
        $this->assertRequestIdentity($request, $payload);
        $this->assertSafety($payload);

        $canonical = $this->canonicalize($payload);
        $hash = hash('sha256', json_encode(
            ['config' => $canonical, 'condition_catalog_hash' => $conditionCatalogHash],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));

        return new EffectiveTradingConfigSnapshot(
            $request,
            $canonical,
            $hash,
            $conditionCatalogHash,
            array_map(static fn (TradingConfigLayer $layer): array => $layer->toLogContext(), $layers),
            $this->canonicalize($provenance),
        );
    }

    private function assertOwnedDocument(TradingConfigLayer $layer): void
    {
        $allowedRoots = match ($layer->type) {
            'base' => ['schema_version', 'units', 'safety'],
            'mode' => ['mode'],
            'setup' => ['setup'],
            'exchange' => ['exchange'],
            'mode_exchange' => ['mode_id', 'mode_version', 'exchange', 'overrides'],
            'environment' => ['environment'],
            default => [],
        };
        $this->assertExactKeys($layer->config, $allowedRoots, sprintf('layer "%s" contains a key not owned by that layer', $layer->type));
        if ($layer->type === 'base') {
            if ($layer->config['schema_version'] !== 'effective-trading-config.v2') {
                throw new TradingConfigException('Unknown effective config schema_version.');
            }
            $this->assertMapping($layer->config['units'], 'base.units');
            $units = $this->mapping($layer->config['units'], 'base.units');
            $this->assertExactKeys($units, ['percent', 'duration', 'price', 'notional'], 'base.units keys are invalid');
            if ($this->canonicalize($units) !== $this->canonicalize(['percent' => 'percentage_points', 'duration' => 'iso8601', 'price' => 'quote_price', 'notional' => 'quote_notional'])) {
                throw new TradingConfigException('base.units values must use the canonical unit vocabulary.');
            }
            $safety = $this->mapping($layer->config['safety'], 'base.safety');
            $this->assertExactKeys($safety, ['mainnet_write_enabled', 'demo_testnet_write_enabled', 'require_stop_loss', 'kill_switch_enabled'], 'base.safety contains an unknown key');
            foreach ($safety as $key => $value) {
                if (!is_bool($value)) throw new TradingConfigException(sprintf('base.safety.%s must be boolean.', $key));
            }
        }
        if ($layer->type === 'exchange') {
            $exchange = $this->mapping($layer->config['exchange'], 'exchange');
            $this->assertExactKeys($exchange, ['id', 'capabilities', 'fees', 'funding', 'precision', 'limits'], 'exchange contains an unknown key');
            $this->assertExchangeSchema($exchange);
        }
        if ($layer->type === 'environment') {
            $environment = $this->mapping($layer->config['environment'], 'environment');
            $this->assertExactKeys($environment, ['id', 'allowed_symbols', 'allowed_markets', 'max_notional', 'dry_run', 'write_enabled', 'kill_switch_enabled', 'require_stop_loss'], 'environment contains an unknown key');
            if (!is_string($environment['id']) || $environment['id'] === '') throw new TradingConfigException('environment.id must be a non-empty string.');
            foreach (['allowed_symbols', 'allowed_markets'] as $list) $this->assertStringList($environment[$list], 'environment.' . $list, true);
            if ($environment['allowed_symbols'] === [] && $environment['allowed_markets'] === []) throw new TradingConfigException('Environment allowlists cannot both be empty.');
            $this->assertNonNegativeFinite($environment['max_notional'], 'environment.max_notional');
            foreach (['dry_run', 'write_enabled', 'kill_switch_enabled', 'require_stop_loss'] as $boolean) {
                if (!is_bool($environment[$boolean])) throw new TradingConfigException(sprintf('environment.%s must be boolean.', $boolean));
            }
        }
        if ($layer->type === 'mode_exchange') {
            $this->assertMapping($layer->config['overrides'], 'mode_exchange.overrides');
        }
    }

    private function assertCompiledSetup(EffectiveTradingConfigRequest $request, TradingConfigLayer $layer, ?string $conditionCatalogHash): void
    {
        $setup = $this->mapping($layer->config['setup'], 'setup');
        $this->assertExactKeys($setup, [
            'schema_version', 'setup_id', 'setup_version', 'status', 'executable', 'publishable',
            'family', 'side', 'thesis', 'hypothesis', 'mode_versions', 'mode_compatibility', 'ast',
            'missing_data_policy', 'data_condition_contract', 'validity_window', 'governance',
            'known_defects', 'ownership_model', 'source_origins', 'contract_provenance',
            'contract_hash', 'condition_catalog_hash', 'blockers', 'payload_hash',
        ], 'Canonical compiled setup payload is incomplete or contains an unknown key');
        $this->assertConditionCatalogConsistency($setup, $conditionCatalogHash);
        $this->assertSetupPayloadIntegrity($setup);
        if ($setup['schema_version'] !== 'compiled-setup.v1'
            || $setup['executable'] !== true
            || $setup['publishable'] !== true
            || $setup['blockers'] !== []
            || !is_string($setup['contract_hash'])
            || preg_match('/^[a-f0-9]{64}$/', $setup['contract_hash']) !== 1) {
            throw new TradingConfigException('Setup layer must be an executable, publishable, blocker-free canonical compiler snapshot with exact hashes.');
        }
        $modeVersions = $this->mapping($setup['mode_versions'], 'setup.mode_versions');
        if (($modeVersions[$request->modeId] ?? null) !== $request->modeVersion) {
            throw new TradingConfigException('Compiled setup mode version does not match the request.');
        }
        if (!is_array($setup['source_origins']) || !array_is_list($setup['source_origins']) || $setup['source_origins'] === []) {
            throw new TradingConfigException('Compiled setup requires immutable source origins.');
        }
        $provenance = $this->mapping($setup['contract_provenance'], 'setup.contract_provenance');
        if ($provenance === []) {
            throw new TradingConfigException('Compiled setup requires contract provenance.');
        }
        $ast = $this->mapping($setup['ast'], 'setup.ast');
        $this->assertExactKeys($ast, ['kind', 'side', 'regime', 'context', 'trigger', 'confirmations', 'filters', 'no_trade_rules', 'execution'], 'Canonical compiled setup AST is incomplete or contains an unknown key');
        if ($ast['kind'] !== 'setup' || $ast['side'] !== $request->side) {
            throw new TradingConfigException('Canonical compiled setup AST identity mismatch.');
        }
        $execution = $this->mapping($ast['execution'], 'setup.ast.execution');
        foreach (['side', 'entry_zone', 'stop', 'targets', 'minimum_net_r', 'invalidation', 'time_stop', 'cost_contract'] as $requiredDecision) {
            if (!array_key_exists($requiredDecision, $execution)) {
                throw new TradingConfigException(sprintf('Canonical compiled setup execution is missing "%s".', $requiredDecision));
            }
        }
    }

    /** @param array<string,mixed> $setup */
    private function assertSetupPayloadIntegrity(array $setup): void
    {
        $expected = $setup['payload_hash'] ?? null;
        $payload = $setup;
        unset($payload['payload_hash']);
        $actual = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        if (!is_string($expected) || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1 || !hash_equals($expected, $actual)) {
            throw new TradingConfigException('Compiled setup payload integrity verification failed.');
        }
    }

    /** @param array<string,mixed> $setup */
    private function assertConditionCatalogConsistency(array $setup, ?string $conditionCatalogHash): void
    {
        $dataContract = $this->mapping($setup['data_condition_contract'], 'setup.data_condition_contract');
        $decision = $this->mapping($dataContract['condition_catalog_hash'] ?? null, 'setup.data_condition_contract.condition_catalog_hash');
        $this->assertExactKeys($decision, ['state', 'value', 'unit', 'source', 'justification'], 'Compiled condition catalog decision is incomplete or contains an unknown key');
        $topLevelHash = $setup['condition_catalog_hash'];

        if (($decision['state'] ?? null) === 'defined') {
            $validHash = static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
            if ($decision['unit'] !== 'sha256'
                || !$validHash($decision['value'])
                || !$validHash($topLevelHash)
                || !$validHash($conditionCatalogHash)
                || $decision['value'] !== $topLevelHash
                || $topLevelHash !== $conditionCatalogHash) {
                throw new TradingConfigException('Compiled setup condition catalog hash conflict between nested decision, top-level payload, and effective request.');
            }

            return;
        }

        if (($decision['state'] ?? null) === 'unresolved'
            && $decision['unit'] === 'sha256'
            && $decision['value'] === null
            && $topLevelHash === null
            && $conditionCatalogHash === null) {
            $blockers = $setup['blockers'] ?? null;
            if ($setup['executable'] !== false
                || $setup['publishable'] !== false
                || !is_array($blockers)
                || !array_is_list($blockers)
                || !in_array('condition_catalog_hash_unresolved', $blockers, true)) {
                throw new TradingConfigException('An unresolved condition catalog requires non-executable and non-publishable setup metadata with a condition_catalog_hash_unresolved blocker.');
            }

            return;
        }

        throw new TradingConfigException('Compiled setup condition catalog hash conflict: unresolved state must remain null in every representation.');
    }

    /** @param array<string,mixed> $document */
    private function assertPairIdentity(EffectiveTradingConfigRequest $request, array $document): void
    {
        if ($document['mode_id'] !== $request->modeId || $document['mode_version'] !== $request->modeVersion || $document['exchange'] !== $request->exchange) {
            throw new TradingConfigException('mode_exchange identity does not match the exact request.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertRequestIdentity(EffectiveTradingConfigRequest $request, array $payload): void
    {
        $checks = [
            'mode.mode_id' => $request->modeId,
            'mode.mode_version' => $request->modeVersion,
            'setup.setup_id' => $request->setupId,
            'setup.setup_version' => $request->setupVersion,
            'setup.side' => $request->side,
            'exchange.id' => $request->exchange,
            'environment.id' => $request->environment,
        ];
        foreach ($checks as $path => $expected) {
            if ($this->readPath($payload, $path) !== $expected) {
                throw new TradingConfigException(sprintf('Resolved identity mismatch at "%s".', $path));
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertSafety(array $payload): void
    {
        $safety = $this->mapping($payload['safety'], 'safety');
        foreach (['mainnet_write_enabled' => false, 'demo_testnet_write_enabled' => false, 'require_stop_loss' => true, 'kill_switch_enabled' => true] as $key => $required) {
            if (($safety[$key] ?? null) !== $required) {
                throw new TradingConfigException(sprintf('Ultimate safety guard "%s" has an unsafe value.', $key));
            }
        }
        $environment = $this->mapping($payload['environment'], 'environment');
        if (($environment['kill_switch_enabled'] ?? null) !== true || !is_bool($environment['dry_run'] ?? null) || !is_bool($environment['write_enabled'] ?? null)) {
            throw new TradingConfigException('Environment execution gates are incomplete or unsafe.');
        }
        if ($environment['write_enabled'] !== false) {
            throw new TradingConfigException('Every #133 environment requires write_enabled=false.');
        }
        if (($environment['require_stop_loss'] ?? null) !== true) {
            throw new TradingConfigException('Every #133 environment requires require_stop_loss=true.');
        }
        $exchange = $this->mapping($payload['exchange'], 'exchange');
        $capabilities = $this->mapping($exchange['capabilities'], 'exchange.capabilities');
        if (($capabilities['stop_loss'] ?? null) !== true) {
            throw new TradingConfigException('Every #133 exchange requires capabilities.stop_loss=true.');
        }
        $limits = $this->mapping($exchange['limits'], 'exchange.limits');
        if ($environment['max_notional'] > $limits['max_notional']) {
            throw new TradingConfigException('Environment max_notional cannot exceed the exchange max_notional safety limit.');
        }
    }

    /**
     * @param array<string,mixed> $document
     * @param list<string> $expected
     */
    private function assertExactKeys(array $document, array $expected, string $message): void
    {
        $actual = array_keys($document);
        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));
        if ($missing !== [] || $extra !== []) {
            throw new TradingConfigException(sprintf(
                '%s; missing=[%s]; extra=[%s]',
                $message,
                implode(', ', $missing),
                implode(', ', $extra),
            ));
        }
    }

    /** @param array<string,mixed> $exchange */
    private function assertExchangeSchema(array $exchange): void
    {
        if (!is_string($exchange['id']) || $exchange['id'] === '') throw new TradingConfigException('exchange.id must be a non-empty string.');

        $capabilities = $this->mapping($exchange['capabilities'], 'exchange.capabilities');
        $this->assertExactKeys($capabilities, ['orders', 'order_types', 'stop_loss', 'take_profit', 'reduce_only'], 'exchange.capabilities requires stop_loss=true and contains an unknown key');
        foreach (['orders', 'stop_loss', 'take_profit', 'reduce_only'] as $key) {
            if (!is_bool($capabilities[$key])) throw new TradingConfigException(sprintf('exchange.capabilities.%s must be boolean.', $key));
        }
        $this->assertStringList($capabilities['order_types'], 'exchange.capabilities.order_types', false);

        $fees = $this->mapping($exchange['fees'], 'exchange.fees');
        $this->assertExactKeys($fees, ['maker_rate', 'taker_rate'], 'exchange.fees contains an unknown key');
        foreach (['maker_rate', 'taker_rate'] as $key) $this->assertRate($fees[$key], 'exchange.fees.' . $key);

        $funding = $this->mapping($exchange['funding'], 'exchange.funding');
        $this->assertExactKeys($funding, ['enabled', 'interval'], 'exchange.funding contains an unknown key');
        if (!is_bool($funding['enabled'])) throw new TradingConfigException('exchange.funding.enabled must be boolean.');
        $this->assertIsoDuration($funding['interval'], 'exchange.funding.interval');

        $precision = $this->mapping($exchange['precision'], 'exchange.precision');
        $this->assertExactKeys($precision, ['price_decimals', 'quantity_decimals'], 'exchange.precision contains an unknown key');
        foreach ($precision as $key => $value) {
            if (!is_int($value) || $value < 0) throw new TradingConfigException(sprintf('exchange.precision.%s must be a non-negative integer.', $key));
        }

        $limits = $this->mapping($exchange['limits'], 'exchange.limits');
        $this->assertExactKeys($limits, ['max_orders', 'min_notional', 'max_notional'], 'exchange.limits contains an unknown key');
        if (!is_int($limits['max_orders']) || $limits['max_orders'] < 1) throw new TradingConfigException('exchange.limits.max_orders must be a positive integer.');
        $this->assertNonNegativeFinite($limits['min_notional'], 'exchange.limits.min_notional');
        $this->assertNonNegativeFinite($limits['max_notional'], 'exchange.limits.max_notional');
        if ($limits['min_notional'] > $limits['max_notional']) throw new TradingConfigException('exchange.limits.min_notional cannot exceed max_notional.');
    }

    private function assertOverrideValue(string $path, mixed $current, mixed $value): void
    {
        if (in_array($path, [
            'mode.risk.trade_budget.value',
            'mode.risk.mode_exposure_cap.value',
            'mode.leverage.value',
        ], true)) {
            $this->assertNonNegativeFinite($value, $path);
            if ($value > $current) throw new TradingConfigException(sprintf('mode_exchange override at "%s" must tighten or preserve the existing cap.', $path));
            return;
        }

        if ($path === 'mode.risk.max_concurrent_positions.value') {
            if (!is_int($value) || $value < 0) throw new TradingConfigException(sprintf('%s must be a non-negative integer.', $path));
            if ($value > $current) throw new TradingConfigException(sprintf('mode_exchange override at "%s" must tighten or preserve the existing cap.', $path));
            return;
        }

        if ($path === 'mode.risk.daily_loss_cap.value') {
            $candidate = $this->mapping($value, $path);
            $existing = $this->mapping($current, $path);
            $keys = ['percent_equity', 'absolute_quote', 'quote_currency'];
            $this->assertExactKeys($candidate, $keys, $path . ' contains an unknown key');
            $this->assertExactKeys($existing, $keys, $path . ' existing value is invalid');
            foreach (['percent_equity', 'absolute_quote'] as $key) {
                $this->assertNonNegativeFinite($candidate[$key], $path . '.' . $key);
                if ($candidate[$key] > $existing[$key]) throw new TradingConfigException(sprintf('mode_exchange override at "%s.%s" must tighten or preserve the existing cap.', $path, $key));
            }
            if (!is_string($candidate['quote_currency']) || $candidate['quote_currency'] === '' || $candidate['quote_currency'] !== $existing['quote_currency']) {
                throw new TradingConfigException($path . '.quote_currency cannot change.');
            }
            return;
        }

        if (in_array($path, ['exchange.fees.maker_rate', 'exchange.fees.taker_rate'], true)) {
            $this->assertRate($value, $path);
            return;
        }

        if ($path === 'exchange.funding.interval') {
            $this->assertIsoDuration($value, $path);
            return;
        }

        if ($path === 'mode.order_policy.value' && $value !== $current) {
            throw new TradingConfigException('mode_exchange order policy cannot weaken or alter the canonical mode policy.');
        }
    }

    private function hasCompatibleOverrideType(string $path, mixed $current, mixed $value): bool
    {
        if (in_array($path, [
            'mode.risk.trade_budget.value',
            'mode.risk.mode_exposure_cap.value',
            'mode.leverage.value',
            'exchange.fees.maker_rate',
            'exchange.fees.taker_rate',
        ], true)) {
            return (is_int($current) || is_float($current)) && (is_int($value) || is_float($value));
        }

        return get_debug_type($current) === get_debug_type($value);
    }

    private function assertRate(mixed $value, string $path): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1) {
            throw new TradingConfigException(sprintf('%s must be finite and between 0 and 1.', $path));
        }
    }

    private function assertNonNegativeFinite(mixed $value, string $path): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new TradingConfigException(sprintf('%s must be a finite number.', $path));
        }
        if ($value < 0) throw new TradingConfigException(sprintf('%s must be non-negative.', $path));
    }

    private function assertIsoDuration(mixed $value, string $path): void
    {
        if (!is_string($value) || $value === '') throw new TradingConfigException(sprintf('%s must be an ISO-8601 duration.', $path));
        try {
            new \DateInterval($value);
        } catch (\Exception) {
            throw new TradingConfigException(sprintf('%s must be an ISO-8601 duration.', $path));
        }
    }

    private function assertStringList(mixed $value, string $path, bool $allowEmpty): void
    {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            throw new TradingConfigException(sprintf('%s must be a%s list of strings.', $path, $allowEmpty ? '' : ' non-empty'));
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') throw new TradingConfigException(sprintf('%s must contain only non-empty strings.', $path));
        }
        if (count(array_unique($value)) !== count($value)) throw new TradingConfigException(sprintf('%s must not contain duplicates.', $path));
    }

    /** @return array<string,mixed> */
    private function mapping(mixed $value, string $path): array
    {
        $this->assertMapping($value, $path);
        /** @var array<string,mixed> $value */
        return $value;
    }

    private function assertMapping(mixed $value, string $path): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new TradingConfigException(sprintf('%s must be a mapping.', $path));
        }
    }

    /** @param array<string,mixed> $payload */
    private function readPath(array $payload, string $path): mixed
    {
        $cursor = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                throw new TradingConfigException(sprintf('mode_exchange override targets missing path "%s".', $path));
            }
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    /** @param array<string,mixed> $payload */
    private function writePath(array &$payload, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $cursor =& $payload;
        foreach ($parts as $part) {
            $cursor =& $cursor[$part];
        }
        $cursor = $value;
    }

    /** @param array<string,array{type:string,name:string,path:string,required:bool}> $provenance */
    private function clearProvenance(array &$provenance, string $path): void
    {
        $prefix = $path . '.';
        foreach (array_keys($provenance) as $ownedPath) {
            if ($ownedPath === $path || str_starts_with($ownedPath, $prefix)) unset($provenance[$ownedPath]);
        }
    }

    /**
     * @param array<string,array{type:string,name:string,path:string,required:bool}> $provenance
     * @param array{type:string,name:string,path:string,required:bool} $context
     */
    private function recordLeaves(array &$provenance, string $path, mixed $value, array $context): void
    {
        if (is_array($value) && !array_is_list($value)) {
            foreach ($value as $key => $child) {
                $this->recordLeaves($provenance, $path . '.' . $key, $child, $context);
            }
            return;
        }
        $provenance[$path] = $context;
    }

    private function normalize(mixed $value, string $path): mixed
    {
        if (is_float($value) && !is_finite($value)) {
            throw new TradingConfigException(sprintf('Non-finite value at "%s".', $path));
        }
        if (!is_array($value)) {
            if (!is_null($value) && !is_scalar($value)) {
                throw new TradingConfigException(sprintf('Non JSON-compatible value at "%s".', $path));
            }
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->normalize($child, $path . '.' . $key);
        }
        return $value;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = $this->canonicalize($child);
        return $value;
    }
}
