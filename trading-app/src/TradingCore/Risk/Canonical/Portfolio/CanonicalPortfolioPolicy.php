<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class CanonicalPortfolioPolicy
{
    private function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $side,
        public string $configHash,
        public float $dailyLossRate,
        public float $dailyLossAbsoluteQuote,
        public string $quoteCurrency,
        public string $dayTimezone,
        public string $dayBoundaryLocal,
        public bool $includeUnrealizedLoss,
        public int $maxConcurrentPositions,
        public bool $includePendingEntries,
        public float $modeExposureRate,
    ) {
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new CanonicalPortfolioException('canonical_portfolio_policy_serialization_forbidden');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new CanonicalPortfolioException('canonical_portfolio_policy_serialization_forbidden');
    }

    public static function fromSnapshot(EffectiveTradingConfigSnapshot $snapshot): self
    {
        if (!$snapshot->executable || $snapshot->blockers !== []) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_snapshot_not_executable');
        }
        if (!\is_string($snapshot->conditionCatalogHash)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->conditionCatalogHash) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->configHash) !== 1
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_hash_invalid');
        }

        $payload = $snapshot->payload();
        $mode = self::mapping($payload, 'mode', 'canonical_portfolio_policy_shape_invalid');
        $setup = self::mapping($payload, 'setup', 'canonical_portfolio_policy_shape_invalid');
        $exchange = self::mapping($payload, 'exchange', 'canonical_portfolio_policy_shape_invalid');
        $environment = self::mapping($payload, 'environment', 'canonical_portfolio_policy_shape_invalid');
        $safety = self::mapping($payload, 'safety', 'canonical_portfolio_policy_shape_invalid');
        $request = $snapshot->request;
        if (
            ($mode['mode_id'] ?? null) !== $request->modeId
            || ($mode['mode_version'] ?? null) !== $request->modeVersion
            || ($setup['setup_id'] ?? null) !== $request->setupId
            || ($setup['setup_version'] ?? null) !== $request->setupVersion
            || ($setup['side'] ?? null) !== $request->side
            || ($exchange['id'] ?? null) !== $request->exchange
            || ($environment['id'] ?? null) !== $request->environment
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_identity_mismatch');
        }
        if (
            ($safety['mainnet_write_enabled'] ?? null) !== false
            || ($environment['write_enabled'] ?? null) !== false
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_safety_invalid');
        }

        $risk = self::mapping($mode, 'risk', 'canonical_portfolio_policy_shape_invalid');
        $daily = self::definedDecision(
            $risk,
            'daily_loss_cap',
            'compound_percent_equity_and_quote_per_day',
            'canonical_portfolio_daily_policy_invalid',
        );
        $dailyValue = self::mapping($daily, 'value', 'canonical_portfolio_daily_policy_invalid');
        self::exactKeys($dailyValue, [
            'percent_equity',
            'absolute_quote',
            'quote_currency',
            'day_timezone',
            'day_boundary_local',
            'include_unrealized_loss',
        ], 'canonical_portfolio_daily_policy_invalid');
        $dailyPercent = self::positiveNumber($dailyValue['percent_equity'], 'canonical_portfolio_daily_policy_invalid');
        if ($dailyPercent > 100.0) {
            throw new CanonicalPortfolioException('canonical_portfolio_daily_policy_invalid');
        }
        $dailyAbsolute = self::positiveNumber($dailyValue['absolute_quote'], 'canonical_portfolio_daily_policy_invalid');
        $currency = $dailyValue['quote_currency'];
        $timezone = $dailyValue['day_timezone'];
        $boundary = $dailyValue['day_boundary_local'];
        $includeUnrealized = $dailyValue['include_unrealized_loss'];
        if (
            !\is_string($currency)
            || preg_match('/\A[A-Z][A-Z0-9]{2,11}\z/D', $currency) !== 1
            || !\is_string($timezone)
            || !self::validTimezone($timezone)
            || !\is_string($boundary)
            || !self::validBoundary($boundary)
            || !\is_bool($includeUnrealized)
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_daily_policy_invalid');
        }

        $concurrency = self::definedDecision(
            $risk,
            'max_concurrent_positions',
            'positions',
            'canonical_portfolio_concurrency_policy_invalid',
        );
        $concurrencyValue = self::mapping($concurrency, 'value', 'canonical_portfolio_concurrency_policy_invalid');
        self::exactKeys($concurrencyValue, ['limit', 'include_pending_entries'], 'canonical_portfolio_concurrency_policy_invalid');
        if (!\is_int($concurrencyValue['limit']) || $concurrencyValue['limit'] < 1 || !\is_bool($concurrencyValue['include_pending_entries'])) {
            throw new CanonicalPortfolioException('canonical_portfolio_concurrency_policy_invalid');
        }

        $exposure = self::decision($risk, 'mode_exposure_cap', 'canonical_portfolio_exposure_policy_unresolved');
        if (($exposure['state'] ?? null) !== 'defined' || !array_key_exists('value', $exposure) || $exposure['value'] === null) {
            throw new CanonicalPortfolioException('canonical_portfolio_exposure_policy_unresolved');
        }
        if (($exposure['unit'] ?? null) !== 'percent_equity_notional') {
            throw new CanonicalPortfolioException('canonical_portfolio_exposure_policy_invalid');
        }
        $exposurePercent = self::positiveNumber($exposure['value'], 'canonical_portfolio_exposure_policy_invalid');

        $expectedHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $snapshot->conditionCatalogHash);
        if (!hash_equals($expectedHash, $snapshot->configHash)) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_hash_mismatch');
        }

        return new self(
            $request->modeId,
            $request->modeVersion,
            $request->setupId,
            $request->setupVersion,
            $request->exchange,
            $request->environment,
            $request->side,
            $snapshot->configHash,
            self::percentageRate($dailyPercent, 'canonical_portfolio_daily_policy_invalid'),
            $dailyAbsolute,
            $currency,
            $timezone,
            $boundary,
            $includeUnrealized,
            $concurrencyValue['limit'],
            $concurrencyValue['include_pending_entries'],
            self::percentageRate($exposurePercent, 'canonical_portfolio_exposure_policy_invalid'),
        );
    }

    public static function fromLineageSnapshot(CanonicalEffectiveConfigSnapshot $snapshot): self
    {
        try {
            $data = $snapshot->toArray();
            self::exactKeys($data, [
                'request',
                'config',
                'config_hash',
                'condition_catalog_hash',
                'ordered_layers',
                'ordered_files',
                'provenance',
                'executable',
                'blockers',
                'snapshot_hash',
            ], 'canonical_portfolio_policy_lineage_invalid');
            $requestData = self::mapping($data, 'request', 'canonical_portfolio_policy_lineage_invalid');
            $requestKeys = array_keys($requestData);
            sort($requestKeys, SORT_STRING);
            $requiredRequestKeys = ['environment', 'exchange', 'mode_id', 'mode_version', 'setup_id', 'setup_version', 'side'];
            $acceptedRequestKeys = [...$requiredRequestKeys, 'execution_capability'];
            sort($acceptedRequestKeys, SORT_STRING);
            if ($requestKeys !== $requiredRequestKeys && $requestKeys !== $acceptedRequestKeys) {
                throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
            }
            foreach ($requiredRequestKeys as $field) {
                if (!\is_string($requestData[$field])) {
                    throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
                }
            }
            $capability = null;
            if (array_key_exists('execution_capability', $requestData)) {
                if (!\is_string($requestData['execution_capability'])) {
                    throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
                }
                $capability = ShadowExecutionCapability::tryFrom($requestData['execution_capability']);
                if (!$capability instanceof ShadowExecutionCapability) {
                    throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
                }
            }
            $config = $data['config'] ?? null;
            $layers = $data['ordered_layers'] ?? null;
            $provenance = $data['provenance'] ?? null;
            $blockers = $data['blockers'] ?? null;
            $orderedFiles = $data['ordered_files'] ?? null;
            if (
                !\is_array($config)
                || ($config !== [] && array_is_list($config))
                || !\is_string($data['config_hash'] ?? null)
                || !\is_string($data['condition_catalog_hash'] ?? null)
                || !\is_array($layers)
                || !array_is_list($layers)
                || !\is_array($provenance)
                || ($provenance !== [] && array_is_list($provenance))
                || !\is_bool($data['executable'] ?? null)
                || !\is_array($blockers)
                || !array_is_list($blockers)
                || !\is_array($orderedFiles)
                || !array_is_list($orderedFiles)
            ) {
                throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
            }
            foreach ($blockers as $blocker) {
                if (!\is_string($blocker)) {
                    throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
                }
            }
            foreach ($layers as $layer) {
                self::assertProofLayer($layer);
            }
            if ($orderedFiles !== array_column($layers, 'path')) {
                throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
            }
            foreach ($provenance as $path => $layer) {
                if (!\is_string($path) || $path === '') {
                    throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
                }
                self::assertProofLayer($layer);
            }

            /** @var list<array{type:string,name:string,path:string,required:bool}> $layers */
            /** @var array<string,array{type:string,name:string,path:string,required:bool}> $provenance */
            /** @var list<string> $blockers */
            return self::fromSnapshot(new EffectiveTradingConfigSnapshot(
                new EffectiveTradingConfigRequest(
                    $requestData['mode_id'],
                    $requestData['mode_version'],
                    $requestData['setup_id'],
                    $requestData['setup_version'],
                    $requestData['exchange'],
                    $requestData['environment'],
                    $requestData['side'],
                    $capability,
                ),
                $config,
                $data['config_hash'],
                $data['condition_catalog_hash'],
                $layers,
                $provenance,
                $data['executable'],
                $blockers,
            ));
        } catch (CanonicalPortfolioException $exception) {
            if ($exception->reasonCode === 'canonical_portfolio_policy_lineage_invalid') {
                throw $exception;
            }

            throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid', [], $exception);
        } catch (\Throwable $exception) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid', [], $exception);
        }
    }

    /** @return array<string, bool|int|string> */
    public function toAdmissionProofArray(): array
    {
        return [
            'mode_id' => $this->modeId,
            'mode_version' => $this->modeVersion,
            'setup_id' => $this->setupId,
            'setup_version' => $this->setupVersion,
            'exchange' => $this->exchange,
            'environment' => $this->environment,
            'side' => $this->side,
            'config_hash' => $this->configHash,
            'daily_loss_rate' => CanonicalPortfolioDecimal::fromFloat($this->dailyLossRate, 'canonical_portfolio_admission_proof_invalid')->stripTrailingZeros()->__toString(),
            'daily_loss_absolute_quote' => CanonicalPortfolioDecimal::fromFloat($this->dailyLossAbsoluteQuote, 'canonical_portfolio_admission_proof_invalid')->stripTrailingZeros()->__toString(),
            'quote_currency' => $this->quoteCurrency,
            'day_timezone' => $this->dayTimezone,
            'day_boundary_local' => $this->dayBoundaryLocal,
            'include_unrealized_loss' => $this->includeUnrealizedLoss,
            'max_concurrent_positions' => $this->maxConcurrentPositions,
            'include_pending_entries' => $this->includePendingEntries,
            'mode_exposure_rate' => CanonicalPortfolioDecimal::fromFloat($this->modeExposureRate, 'canonical_portfolio_admission_proof_invalid')->stripTrailingZeros()->__toString(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromAdmissionProofArray(array $data): self
    {
        self::exactKeys($data, [
            'mode_id',
            'mode_version',
            'setup_id',
            'setup_version',
            'exchange',
            'environment',
            'side',
            'config_hash',
            'daily_loss_rate',
            'daily_loss_absolute_quote',
            'quote_currency',
            'day_timezone',
            'day_boundary_local',
            'include_unrealized_loss',
            'max_concurrent_positions',
            'include_pending_entries',
            'mode_exposure_rate',
        ], 'canonical_portfolio_admission_proof_invalid');
        foreach (['mode_id', 'mode_version', 'setup_id', 'setup_version', 'exchange', 'environment', 'side', 'config_hash', 'quote_currency', 'day_timezone', 'day_boundary_local'] as $field) {
            if (!\is_string($data[$field])) {
                throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
            }
        }
        if (
            !\in_array($data['mode_id'], ['day_trading', 'scalping', 'micro_scalping'], true)
            || preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $data['mode_version']) !== 1
            || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $data['setup_id']) !== 1
            || preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $data['setup_version']) !== 1
            || !\in_array($data['exchange'], ['fake', 'okx', 'hyperliquid'], true)
            || preg_match('/\A[a-z0-9][a-z0-9_.:-]*\z/D', $data['environment']) !== 1
            || !\in_array($data['side'], ['long', 'short'], true)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $data['config_hash']) !== 1
            || preg_match('/\A[A-Z][A-Z0-9]{2,11}\z/D', $data['quote_currency']) !== 1
            || !self::validTimezone($data['day_timezone'])
            || !self::validBoundary($data['day_boundary_local'])
            || !\is_bool($data['include_unrealized_loss'])
            || !\is_int($data['max_concurrent_positions'])
            || $data['max_concurrent_positions'] < 1
            || !\is_bool($data['include_pending_entries'])
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
        }
        $dailyLossRate = self::proofDecimal($data['daily_loss_rate']);
        $dailyLossAbsoluteQuote = self::proofDecimal($data['daily_loss_absolute_quote']);
        $modeExposureRate = self::proofDecimal($data['mode_exposure_rate']);
        if ($dailyLossRate <= 0.0 || $dailyLossRate > 1.0 || $dailyLossAbsoluteQuote <= 0.0 || $modeExposureRate <= 0.0) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
        }

        return new self(
            $data['mode_id'],
            $data['mode_version'],
            $data['setup_id'],
            $data['setup_version'],
            $data['exchange'],
            $data['environment'],
            $data['side'],
            $data['config_hash'],
            $dailyLossRate,
            $dailyLossAbsoluteQuote,
            $data['quote_currency'],
            $data['day_timezone'],
            $data['day_boundary_local'],
            $data['include_unrealized_loss'],
            $data['max_concurrent_positions'],
            $data['include_pending_entries'],
            $modeExposureRate,
        );
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private static function mapping(array $owner, string $key, string $reasonCode): array
    {
        $value = $owner[$key] ?? null;
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private static function decision(array $owner, string $key, string $reasonCode): array
    {
        return self::mapping($owner, $key, $reasonCode);
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private static function definedDecision(array $owner, string $key, string $unit, string $reasonCode): array
    {
        $decision = self::decision($owner, $key, $reasonCode);
        if (($decision['state'] ?? null) !== 'defined' || ($decision['unit'] ?? null) !== $unit || !array_key_exists('value', $decision)) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return $decision;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string>         $keys
     */
    private static function exactKeys(array $value, array $keys, string $reasonCode): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new CanonicalPortfolioException($reasonCode);
        }
    }

    private static function positiveNumber(mixed $value, string $reasonCode): float
    {
        if ((!\is_int($value) && !\is_float($value)) || !\is_finite((float) $value) || (float) $value <= 0.0) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return (float) $value;
    }

    private static function percentageRate(float $percentage, string $reasonCode): float
    {
        return CanonicalPortfolioDecimal::fromFloat($percentage, $reasonCode)
            ->dividedBy(BigDecimal::of('100'), 16, RoundingMode::UNNECESSARY)
            ->stripTrailingZeros()
            ->toFloat();
    }

    private static function validTimezone(string $timezone): bool
    {
        try {
            return (new \DateTimeZone($timezone))->getName() === $timezone;
        } catch (\Exception) {
            return false;
        }
    }

    private static function validBoundary(string $boundary): bool
    {
        if (preg_match('/\A(\d{2}):(\d{2}):(\d{2})\z/D', $boundary, $parts) !== 1) {
            return false;
        }

        return (int) $parts[1] < 24 && (int) $parts[2] < 60 && (int) $parts[3] < 60;
    }

    private static function proofDecimal(mixed $value): float
    {
        if (!\is_string($value) || preg_match('/\A-?(0|[1-9]\d*)(?:\.\d*[1-9])?\z/D', $value) !== 1) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid');
        }

        try {
            return CanonicalPortfolioDecimal::toFiniteFloat(
                BigDecimal::of($value),
                'canonical_portfolio_admission_proof_invalid',
            );
        } catch (\Throwable $exception) {
            throw new CanonicalPortfolioException('canonical_portfolio_admission_proof_invalid', [], $exception);
        }
    }

    private static function assertProofLayer(mixed $layer): void
    {
        if (!\is_array($layer)) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
        }
        $keys = array_keys($layer);
        sort($keys, SORT_STRING);
        if (
            $keys !== ['name', 'path', 'required', 'type']
            || !\is_string($layer['type'])
            || $layer['type'] === ''
            || !\is_string($layer['name'])
            || $layer['name'] === ''
            || !\is_string($layer['path'])
            || $layer['path'] === ''
            || $layer['required'] !== true
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_policy_lineage_invalid');
        }
    }
}
