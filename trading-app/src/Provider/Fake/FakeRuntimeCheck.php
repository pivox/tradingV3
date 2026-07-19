<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeFillCostModel;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeLiquidationPolicy;
use App\Exchange\Readiness\ExchangeReadinessEvaluator;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Exchange\Readiness\ExchangeRuntimeCheckInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FakeRuntimeCheck implements ExchangeRuntimeCheckInterface
{
    public function __construct(
        private FakeExchangeAdapter $adapter,
        private FakeExchangeStateStore $stateStore,
        private ClockInterface $clock,
        private bool $controlledClock = false,
        private bool $marketDataSourceReady = false,
        #[Autowire('%kernel.project_dir%/var/fake_exchange_state.dat')]
        private ?string $stateFile = null,
        private ExchangeReadinessEvaluator $evaluator = new ExchangeReadinessEvaluator(),
    ) {
    }

    public function current(): ExchangeReadinessReport
    {
        $blockingErrors = [];
        $warnings = [];
        $model = $this->adapter->runtimeModelMetadata();
        $persistence = $this->stateFile === null
            ? $this->stateStore->persistenceHealth()
            : FakeExchangeStateStore::persistenceHealthForPath($this->stateFile);
        $metadataReady = \is_string($model['metadata_fixture_version']) && trim($model['metadata_fixture_version']) !== '';
        $precisionReady = \is_string($model['precision_model_version']) && trim($model['precision_model_version']) !== '';

        try {
            if ($this->marketDataSourceReady && $this->stateStore->hasOrderBookTop('BTCUSDT')) {
                $top = $this->stateStore->getOrderBookTop('BTCUSDT');
                $marketDataReady = $top['bid'] > 0.0 && $top['ask'] > $top['bid'];
            } else {
                $marketDataReady = false;
            }
        } catch (\Throwable) {
            $marketDataReady = false;
        }
        if (!$marketDataReady) {
            $warnings[] = 'fake_paper_market_source_not_configured';
        }

        try {
            $stateReadable = $this->stateStore->getBalances() !== [];
        } catch (\Throwable) {
            $stateReadable = false;
        }

        try {
            $this->clock->now();
        } catch (\Throwable) {
            $blockingErrors[] = 'fake_paper_clock_not_ready';
        }
        if (!$this->controlledClock) {
            $blockingErrors[] = 'fake_paper_clock_not_controlled';
        }

        if ($model['fee_model'] !== 'fixed_notional_fee_v1' || $model['fee_rate'] !== 0.0005) {
            $blockingErrors[] = 'fake_paper_fee_model_not_ready';
        }
        if (!self::slippageModelReady($model)) {
            $blockingErrors[] = 'fake_paper_slippage_model_not_ready';
        }
        if (!self::liquidationModelReady($model)) {
            $blockingErrors[] = 'fake_paper_liquidation_model_not_ready';
        }
        if (!$this->liquidationMarksReady()) {
            $blockingErrors[] = 'fake_paper_liquidation_mark_not_ready';
        }
        if ($this->hasPersistedCrossMarginState()) {
            $blockingErrors[] = 'fake_paper_cross_margin_state_unsupported';
        }
        if (!$this->openPositionLiquidationStateReady()) {
            $blockingErrors[] = 'fake_paper_liquidation_position_state_not_ready';
        }
        if (($model['daily_loss_cap_status'] ?? null) === 'limit_reached') {
            $blockingErrors[] = 'fake_paper_daily_loss_cap_reached';
        } elseif (($model['daily_loss_cap_status'] ?? null) !== 'ready') {
            $blockingErrors[] = 'fake_paper_daily_loss_cap_not_computable';
        }
        if (!$persistence['configured']) {
            $warnings[] = 'fake_paper_persistence_not_configured';
        } else {
            if (!$persistence['writable']) {
                $blockingErrors[] = 'fake_paper_state_not_writable';
            }
            if (!$persistence['recovery_ready']) {
                $blockingErrors[] = 'fake_paper_state_recovery_not_ready';
            }
        }
        if (!$this->adapter->capabilities()->supportsAttachedStopLossOnEntry) {
            $blockingErrors[] = 'fake_paper_stop_loss_capability_not_ready';
        }

        $recovery = $this->stateStore->recoveryMetadata();

        return $this->check(new ExchangeReadinessInput(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            environment: 'fake',
            publicConnectivity: $marketDataReady,
            privateReadConnectivity: $stateReadable,
            privateObservability: true,
            instrumentsLoaded: $metadataReady,
            metadataValid: $metadataReady,
            precisionValid: $precisionReady,
            accountReadable: $stateReadable,
            permissionsRead: $stateReadable,
            permissionsTrade: false,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            demoTestnetWriteEnabled: false,
            stopLossCapability: $this->adapter->capabilities()->supportsAttachedStopLossOnEntry,
            killSwitch: true,
            dryRun: true,
            allowedMarkets: [MarketType::PERPETUAL->value],
            maxNotional: 1.0,
            configHash: $recovery['scenario_config_hash'],
            blockingErrors: $blockingErrors,
            warnings: $warnings,
            configProfile: $recovery['engine_version'],
        ));
    }

    public function check(ExchangeReadinessInput $input): ExchangeReadinessReport
    {
        if ($input->exchange !== Exchange::FAKE || $input->marketType !== MarketType::PERPETUAL) {
            throw new \InvalidArgumentException('FakeRuntimeCheck only accepts fake/perpetual.');
        }

        return $this->evaluator->evaluate(new ExchangeReadinessInput(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            environment: $input->environment,
            publicConnectivity: $input->publicConnectivity,
            privateReadConnectivity: $input->privateReadConnectivity,
            privateObservability: $input->privateObservability,
            privateObservabilityStatus: $input->privateObservabilityStatus,
            instrumentsLoaded: $input->instrumentsLoaded,
            metadataValid: $input->metadataValid,
            precisionValid: $input->precisionValid,
            accountReadable: $input->accountReadable,
            permissionsRead: $input->permissionsRead,
            permissionsTrade: false,
            signerConfigured: false,
            signerMatchesAccount: false,
            nonceStoreReady: false,
            collateralReadable: false,
            pollingReady: $input->pollingReady,
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $input->demoTestnetWriteGuard,
            demoTestnetWriteEnabled: false,
            stopLossCapability: $input->stopLossCapability,
            killSwitch: true,
            dryRun: true,
            allowedSymbols: $input->allowedSymbols,
            allowedMarkets: $input->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: $input->blockingErrors,
            warnings: $input->warnings,
            configProfile: $input->configProfile,
        ));
    }

    /**
     * @param array<string,mixed> $model
     * @internal
     */
    public static function slippageModelReady(array $model): bool
    {
        $slippageBps = $model['slippage_bps'] ?? null;

        return ($model['slippage_model'] ?? null) === FakeFillCostModel::MODEL_VERSION
            && (\is_int($slippageBps) || \is_float($slippageBps))
            && \is_finite((float) $slippageBps)
            && (float) $slippageBps === FakeFillCostModel::TAKER_SLIPPAGE_BPS
            && ($model['spread_model'] ?? null) === FakeFillCostModel::SPREAD_MODEL_VERSION;
    }

    /**
     * @param array<string,mixed> $model
     * @internal
     */
    public static function liquidationModelReady(array $model): bool
    {
        return ($model['liquidation_model_version'] ?? null) === FakeLiquidationPolicy::MODEL_VERSION
            && ($model['liquidation_supported_margin_mode'] ?? null) === 'isolated'
            && ($model['liquidation_cross_margin_status'] ?? null) === 'unsupported'
            && ($model['liquidation_guard_buffer_rate'] ?? null) === '0.010000000000'
            && ($model['liquidation_fee_rate'] ?? null) === '0.005000000000'
            && ($model['liquidation_fee_currency'] ?? null) === 'USDT'
            && ($model['liquidation_fee_model_version'] ?? null) === FakeLiquidationPolicy::FEE_MODEL_VERSION
            && ($model['liquidation_mark_price_source'] ?? null) === FakeLiquidationPolicy::MARK_PRICE_SOURCE;
    }

    private function liquidationMarksReady(): bool
    {
        foreach ((new FakeInstrumentCatalog())->all() as $instrument) {
            $markPrice = $this->stateStore->getMarkPrice($instrument->symbol);
            if (!\is_string($markPrice) || !is_numeric($markPrice) || (float) $markPrice <= 0.0) {
                return false;
            }
        }

        return true;
    }

    private function hasPersistedCrossMarginState(): bool
    {
        foreach ($this->stateStore->leverageSettings() as $setting) {
            if ($setting['margin_mode'] === 'cross') {
                return true;
            }
        }
        foreach ($this->stateStore->getOpenPositions() as $position) {
            if (($position->metadata['liquidation_margin_mode'] ?? null) === 'cross') {
                return true;
            }
        }

        return false;
    }

    private function openPositionLiquidationStateReady(): bool
    {
        foreach ($this->stateStore->getOpenPositions() as $position) {
            if (
                ($position->metadata['liquidation_model_version'] ?? null) !== FakeLiquidationPolicy::MODEL_VERSION
                || ($position->metadata['liquidation_margin_mode'] ?? null) !== 'isolated'
            ) {
                return false;
            }
            foreach ([
                'liquidation_position_identity',
                'liquidation_quantity_decimal',
                'liquidation_entry_price_decimal',
                'liquidation_isolated_margin_decimal',
                'liquidation_contract_size_decimal',
                'liquidation_maintenance_margin_rate',
                'liquidation_price_decimal',
                'liquidation_guard_price_decimal',
            ] as $key) {
                $value = $position->metadata[$key] ?? null;
                if (!\is_string($value)) {
                    return false;
                }
                if ($key === 'liquidation_position_identity') {
                    if (preg_match('/^fake-position-[a-f0-9]{40}$/D', $value) !== 1) {
                        return false;
                    }

                    continue;
                }
                if (!is_numeric($value)) {
                    return false;
                }
            }
        }

        return true;
    }
}
