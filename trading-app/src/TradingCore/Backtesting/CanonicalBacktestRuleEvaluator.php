<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;

final readonly class CanonicalBacktestRuleEvaluator implements CanonicalBacktestRuleEvaluatorInterface
{
    private const MAX_CANONICAL_BYTES = 8_388_608;
    private const MAX_CANONICAL_DEPTH = 128;
    private const PHP_INT64_MIN_AS_FLOAT = -9_223_372_036_854_775_808.0;
    private const PHP_INT64_MAX_EXCLUSIVE_AS_FLOAT = 9_223_372_036_854_775_808.0;

    private const REQUEST_KEYS = [
        'schema_version',
        'request_id',
        'effective_config_snapshot',
        'symbol',
        'market_type',
        'evaluated_at',
        'indicators_by_timeframe',
    ];

    private const SNAPSHOT_KEYS = [
        'request', 'config', 'config_hash', 'condition_catalog_hash', 'ordered_layers',
        'ordered_files', 'provenance', 'executable', 'blockers', 'snapshot_hash',
    ];

    private const CONFIG_REQUEST_KEYS = [
        'mode_id', 'mode_version', 'setup_id', 'setup_version', 'exchange', 'environment',
        'side', 'execution_capability',
    ];

    private const INDICATOR_IDENTITY_KEYS = [
        'timeframe', 'symbol', 'exchange', 'environment', 'market_type',
    ];

    public function __construct(
        private EffectiveTradingConfigResolverInterface $resolver,
        private CanonicalSetupRuleRuntime $runtime,
    ) {
    }

    /** @param array<string,mixed> $request */
    public function evaluate(#[\SensitiveParameter] array $request): CanonicalBacktestRuleEvaluation
    {
        $this->assertExactKeys($request, self::REQUEST_KEYS, 'canonical_backtest_rule_request_shape_invalid');
        if (($request['schema_version'] ?? null) !== 'canonical-backtest-rule-request.v1') {
            throw new \InvalidArgumentException('canonical_backtest_rule_schema_invalid');
        }
        $requestId = $request['request_id'] ?? null;
        if (!\is_string($requestId)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $requestId) !== 1
        ) {
            throw new \InvalidArgumentException('canonical_backtest_rule_request_id_invalid');
        }
        $symbol = $request['symbol'] ?? null;
        if (!\is_string($symbol) || preg_match('/\A[A-Z0-9]{2,32}\z/D', $symbol) !== 1) {
            throw new \InvalidArgumentException('canonical_backtest_rule_symbol_invalid');
        }
        $marketType = $request['market_type'] ?? null;
        if (!\is_string($marketType) || !\in_array($marketType, ['perpetual', 'spot'], true)) {
            throw new \InvalidArgumentException('canonical_backtest_rule_market_type_invalid');
        }
        $evaluatedAtRaw = $request['evaluated_at'] ?? null;
        $evaluatedAt = $this->utcInstant($evaluatedAtRaw, 'canonical_backtest_rule_evaluated_at_invalid');

        $snapshot = $request['effective_config_snapshot'] ?? null;
        if (!\is_array($snapshot) || array_is_list($snapshot)) {
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_invalid');
        }
        $this->assertExactKeys($snapshot, self::SNAPSHOT_KEYS, 'canonical_backtest_rule_snapshot_invalid');
        $configRequest = $snapshot['request'] ?? null;
        if (!\is_array($configRequest) || array_is_list($configRequest)) {
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_invalid');
        }
        $this->assertExactKeys($configRequest, self::CONFIG_REQUEST_KEYS, 'canonical_backtest_rule_snapshot_invalid');
        if (($configRequest['exchange'] ?? null) !== 'fake'
            || !\in_array($configRequest['environment'] ?? null, ['local', 'test'], true)
            || ($configRequest['execution_capability'] ?? null) !== ShadowExecutionCapability::Backtest->value
        ) {
            throw new \InvalidArgumentException('canonical_backtest_rule_fake_backtest_required');
        }
        if (($snapshot['executable'] ?? null) !== true
            || !\is_array($snapshot['blockers'] ?? null)
            || !array_is_list($snapshot['blockers'])
            || $snapshot['blockers'] !== []
        ) {
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_not_executable');
        }

        try {
            $effectiveRequest = new EffectiveTradingConfigRequest(
                $this->requiredString($configRequest, 'mode_id'),
                $this->requiredString($configRequest, 'mode_version'),
                $this->requiredString($configRequest, 'setup_id'),
                $this->requiredString($configRequest, 'setup_version'),
                'fake',
                $this->requiredString($configRequest, 'environment'),
                $this->requiredString($configRequest, 'side'),
                ShadowExecutionCapability::Backtest,
            );
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_invalid', 0, $exception);
        }

        $identity = [
            ...$effectiveRequest->toArray(),
            'config_hash' => $snapshot['config_hash'] ?? null,
            'condition_catalog_hash' => $snapshot['condition_catalog_hash'] ?? null,
        ];
        try {
            $canonicalSnapshot = CanonicalEffectiveConfigSnapshot::fromArray($snapshot, $identity);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_invalid', 0, $exception);
        }

        try {
            $resolvedSnapshot = $this->resolver->resolve($effectiveRequest)->toArray();
            if (!hash_equals($this->encodeCanonical($resolvedSnapshot), $this->encodeCanonical($snapshot))) {
                throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_mismatch');
            }
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'canonical_backtest_rule_snapshot_mismatch') {
                throw $exception;
            }
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_resolution_failed', 0, $exception);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_resolution_failed', 0, $exception);
        }

        $indicators = $request['indicators_by_timeframe'] ?? null;
        if (!\is_array($indicators) || array_is_list($indicators) || $indicators === [] || \count($indicators) > 16) {
            throw new \InvalidArgumentException('canonical_backtest_rule_indicators_invalid');
        }
        $environment = $this->requiredString($configRequest, 'environment');
        foreach ($indicators as $timeframe => $indicator) {
            if (!\is_string($timeframe)
                || preg_match('/\A(?:1m|5m|15m|1h|4h)\z/D', $timeframe) !== 1
                || !\is_array($indicator)
                || array_is_list($indicator)
            ) {
                throw new \InvalidArgumentException('canonical_backtest_rule_indicators_invalid');
            }
            $this->utcInstant($indicator['kline_time'] ?? null, 'canonical_backtest_rule_kline_time_invalid');
            $snapshotIdentity = $indicator['snapshot_identity'] ?? null;
            if (!\is_array($snapshotIdentity) || array_is_list($snapshotIdentity)) {
                throw new \InvalidArgumentException('canonical_backtest_rule_indicator_identity_invalid');
            }
            $this->assertExactKeys(
                $snapshotIdentity,
                self::INDICATOR_IDENTITY_KEYS,
                'canonical_backtest_rule_indicator_identity_invalid',
            );
            foreach ([
                'timeframe' => $timeframe,
                'symbol' => $symbol,
                'exchange' => 'fake',
                'environment' => $environment,
                'market_type' => $marketType,
            ] as $field => $expected) {
                if (($snapshotIdentity[$field] ?? null) === $expected) {
                    continue;
                }
                throw new \InvalidArgumentException('canonical_backtest_rule_indicator_identity_mismatch');
            }
        }

        try {
            $normalizedRequest = self::normalizeIntegralFloats($request, 0);
            if (!\is_array($normalizedRequest)) {
                throw new \LogicException('The normalized canonical request must remain an array.');
            }
            $normalizedIndicators = $normalizedRequest['indicators_by_timeframe'] ?? null;
            $normalizedSnapshot = $normalizedRequest['effective_config_snapshot'] ?? null;
            if (!\is_array($normalizedIndicators) || !\is_array($normalizedSnapshot)) {
                throw new \LogicException('Canonical request mappings must survive normalization.');
            }
            $canonicalSnapshot = CanonicalEffectiveConfigSnapshot::fromArray($normalizedSnapshot, $identity);
            $canonicalInput = $this->encodeCanonical($normalizedRequest);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid', 0, $exception);
        }
        $inputHash = 'sha256:' . hash('sha256', $canonicalInput);
        $configHash = $this->requiredHash($snapshot, 'config_hash');
        $catalogHash = $this->requiredHash($snapshot, 'condition_catalog_hash');
        $snapshotHash = $this->requiredHash($snapshot, 'snapshot_hash');
        $lineageSuffix = substr($inputHash, 7, 24);
        $lineage = LineageContext::fromOrchestratorPayload([
            'origin' => LineageContext::ORIGIN_REPLAY,
            'orchestration_run_id' => 'backtest-rule-' . $lineageSuffix,
            'correlation_run_id' => 'backtest-rule-' . $lineageSuffix,
            'orchestration_set_id' => 'backtest-set-' . $lineageSuffix,
            'replay_of_run_id' => 'backtest-source-' . $lineageSuffix,
            'mode_id' => $effectiveRequest->modeId,
            'mode_version' => $effectiveRequest->modeVersion,
            'setup_id' => $effectiveRequest->setupId,
            'setup_version' => $effectiveRequest->setupVersion,
            'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'side' => strtoupper($effectiveRequest->side),
            'exchange' => 'fake',
            'environment' => $environment,
            'market_type' => $marketType,
            'symbol' => $symbol,
            'dry_run' => true,
            'effective_config_reference' => 'effective-config-snapshot:' . $snapshotHash,
            'effective_config_snapshot' => $canonicalSnapshot->toArray(),
        ]);

        $runtimeResult = $this->runtime->evaluate($lineage, $normalizedIndicators, $evaluatedAt);
        $trace = $runtimeResult->trace;
        unset($trace['plan_cache_hit']);
        if (array_key_exists('evaluated_at', $trace)) {
            $trace['evaluated_at'] = $evaluatedAtRaw;
        }
        $result = [
            'schema_version' => 'canonical-backtest-rule-result.v1',
            'request_id' => $requestId,
            'mode_id' => $effectiveRequest->modeId,
            'mode_version' => $effectiveRequest->modeVersion,
            'setup_id' => $effectiveRequest->setupId,
            'setup_version' => $effectiveRequest->setupVersion,
            'side' => $effectiveRequest->side,
            'exchange' => 'fake',
            'environment' => $environment,
            'market_type' => $marketType,
            'symbol' => $symbol,
            'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'snapshot_hash' => $snapshotHash,
            'evaluated_at' => $evaluatedAtRaw,
            'passed' => $runtimeResult->passed,
            'reason_code' => $runtimeResult->reasonCode,
            'trace' => $trace,
            'input_hash' => $inputHash,
        ];
        $result['result_hash'] = 'sha256:' . hash('sha256', $this->encodeCanonical($result));

        return new CanonicalBacktestRuleEvaluation($result);
    }

    /** @param array<string,mixed> $payload */
    public static function canonicalHash(#[\SensitiveParameter] array $payload): string
    {
        return 'sha256:' . hash('sha256', self::canonicalJson($payload));
    }

    /** @param array<string,mixed> $payload */
    public static function canonicalJson(#[\SensitiveParameter] array $payload): string
    {
        return self::encodeCanonicalValue($payload);
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $expected
     */
    private function assertExactKeys(array $value, array $expected, string $reason): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException($reason);
        }
    }

    /** @param array<string,mixed> $value */
    private function requiredString(array $value, string $field): string
    {
        if (!\is_string($value[$field] ?? null) || $value[$field] === '') {
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_invalid');
        }

        return $value[$field];
    }

    /** @param array<string,mixed> $value */
    private function requiredHash(array $value, string $field): string
    {
        $hash = $value[$field] ?? null;
        if (!\is_string($hash) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new \InvalidArgumentException('canonical_backtest_rule_snapshot_invalid');
        }

        return $hash;
    }

    private function utcInstant(mixed $value, string $reason): \DateTimeImmutable
    {
        if (!\is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException($reason);
        }
        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s\Z';
        $instant = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$instant instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new \InvalidArgumentException($reason);
        }

        return $instant;
    }

    private function encodeCanonical(mixed $value): string
    {
        return self::encodeCanonicalValue($value);
    }

    private static function encodeCanonicalValue(mixed $value): string
    {
        $precision = ini_get('serialize_precision');
        if (!\is_string($precision)) {
            throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid');
        }
        $changed = $precision !== '-1';
        if ($changed && ini_set('serialize_precision', '-1') === false) {
            throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid');
        }

        try {
            $encoded = self::encodeValue(self::normalizeIntegralFloats($value), 0);
        } finally {
            if ($changed && ini_set('serialize_precision', $precision) === false) {
                throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid');
            }
        }
        if (strlen($encoded) > self::MAX_CANONICAL_BYTES) {
            throw new \InvalidArgumentException('canonical_backtest_rule_input_too_large');
        }

        return $encoded;
    }

    private static function encodeValue(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_CANONICAL_DEPTH) {
            throw new \InvalidArgumentException('canonical_backtest_rule_input_depth_exceeded');
        }
        if (\is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(
                    static fn (mixed $item): string => self::encodeValue($item, $depth + 1),
                    $value,
                )) . ']';
            }
            $membersByKey = [];
            foreach ($value as $key => $item) {
                $membersByKey[] = [(string) $key, $item];
            }
            usort(
                $membersByKey,
                static fn (array $left, array $right): int => strcmp($left[0], $right[0]),
            );
            $members = [];
            foreach ($membersByKey as [$key, $item]) {
                $members[] = self::encodeScalar($key) . ':' . self::encodeValue($item, $depth + 1);
            }

            return '{' . implode(',', $members) . '}';
        }

        return self::encodeScalar($value);
    }

    private static function encodeScalar(mixed $value): string
    {
        if ($value !== null && !\is_scalar($value)) {
            throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid');
        }
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid', 0, $exception);
        }
    }

    private static function normalizeIntegralFloats(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_CANONICAL_DEPTH) {
            throw new \InvalidArgumentException('canonical_backtest_rule_input_depth_exceeded');
        }
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalizeIntegralFloats($item, $depth + 1);
            }

            return $value;
        }
        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid');
            }
            if (floor($value) === $value) {
                // PHP_INT_MAX cannot be compared safely to a float: it rounds to +2^63,
                // whose cast wraps to PHP_INT_MIN. Use exact binary64 power-of-two bounds.
                if ($value < self::PHP_INT64_MIN_AS_FLOAT
                    || $value >= self::PHP_INT64_MAX_EXCLUSIVE_AS_FLOAT
                ) {
                    throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid');
                }

                return (int) $value;
            }
        }
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('canonical_backtest_rule_input_invalid');
    }
}
