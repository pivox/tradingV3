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
            if (get_debug_type($current) !== get_debug_type($value)) {
                throw new TradingConfigException(sprintf('mode_exchange override type mismatch at "%s".', $path));
            }
            $this->writePath($payload, $path, $this->normalize($value, $path));
            $provenance[$path] = $pair->toLogContext();
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
            $this->assertAllowedKeys($layer->config['units'], ['percent', 'duration', 'price', 'notional'], 'base.units');
            $this->assertExactKeys($this->mapping($layer->config['safety'], 'base.safety'), ['mainnet_write_enabled', 'demo_testnet_write_enabled', 'require_stop_loss', 'kill_switch_enabled'], 'base.safety contains an unknown key');
        }
        if ($layer->type === 'exchange') {
            $exchange = $this->mapping($layer->config['exchange'], 'exchange');
            $this->assertExactKeys($exchange, ['id', 'capabilities', 'fees', 'funding', 'precision', 'limits'], 'exchange contains an unknown key');
            $this->assertAllowedMapping($exchange, 'capabilities', ['orders', 'order_types', 'stop_loss', 'take_profit', 'reduce_only']);
            $this->assertAllowedMapping($exchange, 'fees', ['maker_rate', 'taker_rate']);
            $this->assertAllowedMapping($exchange, 'funding', ['enabled', 'interval']);
            $this->assertAllowedMapping($exchange, 'precision', ['price_decimals', 'quantity_decimals']);
            $this->assertAllowedMapping($exchange, 'limits', ['max_orders', 'min_notional', 'max_notional']);
        }
        if ($layer->type === 'environment') {
            $this->assertExactKeys($this->mapping($layer->config['environment'], 'environment'), ['id', 'allowed_symbols', 'allowed_markets', 'max_notional', 'dry_run', 'write_enabled', 'kill_switch_enabled', 'require_stop_loss'], 'environment contains an unknown key');
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
            'contract_hash', 'condition_catalog_hash', 'blockers',
        ], 'Canonical compiled setup payload is incomplete or contains an unknown key');
        $this->assertConditionCatalogConsistency($setup, $conditionCatalogHash);
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
    }

    /**
     * @param array<string,mixed> $document
     * @param list<string> $expected
     */
    private function assertExactKeys(array $document, array $expected, string $message): void
    {
        $actual = array_keys($document);
        sort($actual); sort($expected);
        if ($actual !== $expected) {
            throw new TradingConfigException($message . ': ' . implode(', ', array_values(array_diff($actual, $expected))));
        }
    }

    /**
     * @param array<string,mixed> $document
     * @param list<string> $allowed
     */
    private function assertAllowedKeys(array $document, array $allowed, string $path): void
    {
        $unknown = array_values(array_diff(array_keys($document), $allowed));
        if ($unknown !== []) {
            throw new TradingConfigException(sprintf('%s contains unknown key "%s".', $path, $unknown[0]));
        }
    }

    /**
     * @param array<string,mixed> $document
     * @param list<string> $allowed
     */
    private function assertAllowedMapping(array $document, string $key, array $allowed): void
    {
        $value = $this->mapping($document[$key], 'exchange.' . $key);
        $this->assertAllowedKeys($value, $allowed, 'exchange.' . $key);
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
