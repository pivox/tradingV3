<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidSignedActionClientInterface;
use App\Exchange\Hyperliquid\HyperliquidSignedActionResult;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Provider\Hyperliquid\Dto\HyperliquidInstrumentMetadataDto;
use App\Provider\Hyperliquid\HyperliquidIsolatedLiquidationSolver;
use App\Provider\Hyperliquid\HyperliquidInstrumentMetadataProviderInterface;
use App\Provider\Hyperliquid\HyperliquidMarginSafetyEvidenceProviderInterface;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessProbeInterface;
use App\Provider\Hyperliquid\HyperliquidNonceManagerInterface;
use App\Provider\Hyperliquid\HyperliquidNonceScope;
use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Port\ExecutionPortInterface;
use App\TradingCore\Execution\Safety\DemoTradingKillSwitchService;
use App\TradingCore\Execution\Safety\DemoTradingMutationAttempt;
use App\TradingCore\Execution\Safety\ExchangeRuntimeEnvironment;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\SlTp\Dto\LiquidationCheckRequest;
use App\TradingCore\SlTp\Service\LiquidationGuard;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;

final readonly class HyperliquidTestnetExecutionPort implements ExecutionPortInterface, HyperliquidTestnetExecutionPortInterface
{
    private const MAX_MARGIN_EVIDENCE_AGE_MILLISECONDS = 2_000;
    private const MIN_LIQUIDATION_DISTANCE_RATIO = 3.0;

    public function __construct(
        private HyperliquidConfig $config,
        private HyperliquidMutationReadinessProbeInterface $readiness,
        private HyperliquidMutationReadinessGate $readinessGate,
        private HyperliquidInstrumentMetadataProviderInterface $metadata,
        private HyperliquidExecutionStateProviderInterface $executionState,
        private HyperliquidExecutionStatePolicy $executionStatePolicy,
        private DemoTradingKillSwitchService $killSwitch,
        private HyperliquidKillSwitchTripInterface $durableTrip,
        private HyperliquidNonceManagerInterface $nonces,
        private HyperliquidActionFactory $actions,
        private HyperliquidSignedActionClientInterface $signedActions,
        private HyperliquidCompensationInterface $compensation,
        private HyperliquidExecutionLockInterface $executionLock,
        private HyperliquidLeveragePolicy $leveragePolicy,
        private HyperliquidMarginSafetyEvidenceProviderInterface $marginEvidence,
        private HyperliquidIsolatedLiquidationSolver $liquidationSolver,
        private LiquidationGuard $liquidationGuard,
        private ClockInterface $clock,
    ) {
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $plan = $request->orderPlan;
        $initialReport = $this->reportOrNull();
        $initialReasons = $this->initialReasons($request, $initialReport);
        if ($initialReasons !== []) {
            return $this->rejected($plan, 'hyperliquid_testnet_preflight_rejected', $initialReasons);
        }

        try {
            $lease = $this->executionLock->acquire();
        } catch (\Throwable) {
            return $this->rejected($plan, 'hyperliquid_execution_lock_unavailable', ['hyperliquid_execution_lock_unavailable']);
        }
        if ($lease === null) {
            return $this->rejected($plan, 'hyperliquid_execution_in_flight', ['hyperliquid_execution_in_flight']);
        }

        try {
            $result = $this->executeUnderLock($request);
            if ($result->status === ExecutionStatus::Accepted && !$this->acceptedResultIsProven($result, $request)) {
                $result = $this->tripAndFail(
                    $plan,
                    $this->correlationId($request),
                    'hyperliquid_accepted_result_invariant_failed',
                );
            }
        } catch (HyperliquidDurableTripPersistenceException) {
            $lease->retain();

            return $this->failed($plan, $this->correlationId($request), 'hyperliquid_durable_quarantine_failed');
        }

        try {
            $lease->release();
        } catch (\Throwable) {
            try {
                $this->durableTrip->trip('hyperliquid_execution_lock_release_failed', []);
            } catch (\Throwable) {
                $lease->retain();

                return $this->failed($plan, $this->correlationId($request), 'hyperliquid_execution_lock_release_quarantine_failed');
            }
            $lease->retain();

            return $this->failed($plan, $this->correlationId($request), 'hyperliquid_execution_lock_release_failed');
        }

        return $result;
    }

    private function acceptedResultIsProven(ExecutionResult $result, ExecutionRequest $request): bool
    {
        $submittedClientOrderId = $request->orderPlan->clientOrderId;

        return is_string($submittedClientOrderId)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $submittedClientOrderId) === 1
            && is_string($result->clientOrderId)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $result->clientOrderId) === 1
            && hash_equals($submittedClientOrderId, $result->clientOrderId)
            && is_string($result->exchangeOrderId)
            && preg_match('/^[1-9][0-9]{0,19}$/D', $result->exchangeOrderId) === 1
            && ($result->metadata['protection_confirmed'] ?? null) === true;
    }

    private function executeUnderLock(ExecutionRequest $request): ExecutionResult
    {
        $plan = $request->orderPlan;
        $report = $this->reportOrNull();
        $reasons = $this->initialReasons($request, $report);
        if ($reasons !== [] || !($report instanceof ExchangeReadinessReport)) {
            return $this->rejected($plan, 'hyperliquid_testnet_revalidation_rejected', $reasons ?: ['readiness_report_unavailable']);
        }

        try {
            $metadata = $this->metadata->getInstrumentMetadata($plan->symbol);
        } catch (\Throwable) {
            return $this->rejected($plan, 'hyperliquid_metadata_unavailable', ['hyperliquid_metadata_unavailable']);
        }
        $metadataReasons = $this->metadataReasons($metadata, $plan);
        if ($metadataReasons !== [] || !($metadata instanceof HyperliquidInstrumentMetadataDto)) {
            return $this->rejected($plan, 'hyperliquid_metadata_rejected', $metadataReasons ?: ['hyperliquid_metadata_missing']);
        }

        try {
            $state = $this->executionState->current($plan->symbol);
        } catch (\Throwable) {
            return $this->rejected($plan, 'hyperliquid_execution_state_unavailable', ['hyperliquid_execution_state_unavailable']);
        }
        $stateReasons = $this->flatStateReasons($state, $plan->symbol);
        if ($stateReasons !== []) {
            return $this->rejected($plan, 'hyperliquid_execution_state_rejected', $stateReasons);
        }

        try {
            $shape = $this->shape($metadata, $plan);
        } catch (\Throwable) {
            return $this->rejected($plan, 'hyperliquid_order_shape_rejected', ['hyperliquid_order_decimal_invalid']);
        }
        if ($shape['reasons'] !== []) {
            return $this->rejected($plan, 'hyperliquid_order_shape_rejected', $shape['reasons']);
        }

        $notional = (float) (string) BigDecimal::of($shape['price'])->multipliedBy($shape['quantity']);
        if (!is_finite($notional) || $notional <= 0.0 || $report->maxNotional === null || $notional > $report->maxNotional) {
            return $this->rejected($plan, 'hyperliquid_notional_rejected', ['max_notional_exceeded']);
        }

        $correlationId = $this->correlationId($request);
        $decision = $this->killSwitch->evaluate(new DemoTradingMutationAttempt(
            exchange: Exchange::HYPERLIQUID,
            environment: ExchangeRuntimeEnvironment::TESTNET,
            mode: $request->mode->value,
            profile: $report->configProfile ?? '',
            market: $plan->marketType,
            symbol: $plan->symbol,
            notional: $notional,
            clientOrderId: $plan->clientOrderId,
            action: 'position_tpsl',
            mainnetWriteEnabled: !$report->mainnetWriteGuard,
            demoTestnetWriteEnabled: $report->demoTestnetWriteGuard,
            effectiveKillSwitchEnabled: $report->killSwitch,
            requireStopLoss: true,
            stopLossPresent: true,
            allowedSymbols: $report->allowedSymbols,
            allowedMarkets: $report->allowedMarkets,
            maxNotional: $report->maxNotional,
            correlationIds: ['correlation_id' => $correlationId],
            auditContext: ['config_hash' => $report->configHash, 'profile' => $report->configProfile],
            privateObservabilityStatus: null,
            hyperliquidPollingObservabilityStatus: $report->hyperliquidPollingObservabilityStatus,
        ));
        if (!$decision->allowed) {
            return $this->rejected($plan, 'demo_trading_safety_blocked', $decision->reasons);
        }

        $requiresLeverageUpdate = $this->leveragePolicy->requiresUpdate($state->observedLeverage, $plan->leverage)
            || $this->executionStatePolicy->requiresIsolatedModeUpdate($state);
        $marginReasons = $this->marginSafetyReasons(
            $plan,
            $shape,
            $state,
            requireRequestedAccountState: !$requiresLeverageUpdate,
        );
        if ($marginReasons !== []) {
            return $this->rejected($plan, 'hyperliquid_margin_safety_rejected', $marginReasons);
        }

        try {
            $scope = $this->nonceScope();
        } catch (\Throwable) {
            return $this->rejected($plan, 'hyperliquid_nonce_scope_invalid', ['hyperliquid_nonce_scope_invalid']);
        }
        if ($requiresLeverageUpdate) {
            $leverageResult = $this->updateLeverage($metadata->assetId, $plan, $scope, $correlationId);
            if ($leverageResult instanceof ExecutionResult) {
                return $leverageResult;
            }
            $marginReasons = $this->marginSafetyReasons(
                $plan,
                $shape,
                state: null,
                requireRequestedAccountState: true,
            );
            if ($marginReasons !== []) {
                return $this->rejected($plan, 'hyperliquid_margin_safety_revalidation_rejected', $marginReasons);
            }
        }

        $entry = $this->entryRequest($plan, $shape['price'], $shape['quantity']);
        $stop = $this->stopRequest($plan, $shape['stop'], $shape['quantity'], $metadata->priceTick);
        try {
            $finalState = $this->executionState->current($plan->symbol);
        } catch (\Throwable) {
            return $this->rejected(
                $plan,
                'hyperliquid_final_execution_state_unavailable',
                ['hyperliquid_final_execution_state_unavailable'],
            );
        }
        $finalStateReasons = $this->flatStateReasons($finalState, $plan->symbol);
        if ($finalStateReasons !== []) {
            return $this->rejected($plan, 'hyperliquid_final_execution_state_rejected', $finalStateReasons);
        }
        $state = $finalState;
        if (!$this->nonceReady($scope)) {
            return $this->rejected($plan, 'hyperliquid_nonce_not_ready', ['hyperliquid_nonce_store_not_ready']);
        }
        try {
            $nonce = $this->nonces->nextNonce($scope);
            $submission = $this->signedActions->submit(
                $this->actions->positionTpsl($metadata->assetId, $entry, $stop),
                $nonce,
                $correlationId,
            );
        } catch (\Throwable) {
            return $this->compensate($plan, $metadata, $state, $scope, $correlationId, null);
        }

        return $this->mapSubmission($submission, $plan, $metadata, $state, $scope, $correlationId, $shape['quantity']);
    }

    /** @return list<string> */
    private function flatStateReasons(HyperliquidExecutionState $state, string $symbol): array
    {
        $reasons = $this->executionStatePolicy->blockingReasons($state, $symbol);
        if ($state->hasOpenPosition) {
            $reasons[] = 'hyperliquid_existing_position_not_flat';
        }
        if ($state->openOrderCount !== 0) {
            $reasons[] = 'hyperliquid_existing_open_orders_not_flat';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param array{price:string,quantity:string,stop:string,reasons:list<string>} $shape
     *
     * @return list<string>
     */
    private function marginSafetyReasons(
        OrderPlan $plan,
        array $shape,
        ?HyperliquidExecutionState $state,
        bool $requireRequestedAccountState,
    ): array {
        try {
            $evidence = $this->marginEvidence->current($plan->symbol);
            $age = $this->milliseconds($this->clock->now()) - $this->milliseconds($evidence->observedAt);
            $account = strtolower($this->config->signingAccountAddress());
            $coin = str_ends_with($plan->symbol, 'USDT') ? substr($plan->symbol, 0, -4) : '';
            if ($age < 0 || $age > self::MAX_MARGIN_EVIDENCE_AGE_MILLISECONDS
                || $evidence->symbol !== $plan->symbol
                || $evidence->coin !== $coin
                || $evidence->observedCoin !== $coin
                || !hash_equals($account, $evidence->accountAddress)
                || !hash_equals($account, $evidence->observedUser)
                || $evidence->tiers === []
            ) {
                return ['hyperliquid_margin_evidence_invalid'];
            }
            if ($state instanceof HyperliquidExecutionState && $state->hasOpenPosition
                && $state->observedLeverage !== null
                && ($evidence->observedLeverage !== $state->observedLeverage
                    || $evidence->observedMarginMode !== $state->observedMarginMode)
            ) {
                return ['hyperliquid_margin_account_state_mismatch'];
            }
            if ($requireRequestedAccountState
                && ($evidence->observedLeverage !== $plan->leverage || $evidence->observedMarginMode !== 'isolated')
            ) {
                return ['hyperliquid_isolated_margin_not_confirmed'];
            }
            $liquidation = $this->liquidationSolver->solve(
                $evidence,
                $shape['price'],
                $shape['quantity'],
                $plan->leverage,
                $plan->side,
            );
            $check = $this->liquidationGuard->check(new LiquidationCheckRequest(
                symbol: $plan->symbol,
                instrument: $plan->symbol,
                exchange: $plan->exchange,
                marketType: $plan->marketType,
                direction: $plan->side,
                entryPrice: (float) $shape['price'],
                stopPrice: (float) $shape['stop'],
                leverage: $plan->leverage,
                maintenanceMarginRate: $liquidation->maintenanceMarginRate,
                liquidationPrice: $this->liquidationSolver->toConservativeFloat($liquidation, $plan->side),
                minDistanceRatio: self::MIN_LIQUIDATION_DISTANCE_RATIO,
                metadata: [
                    'margin_table_id' => $evidence->marginTableId,
                    'tier_lower_bound' => $liquidation->tierLowerBound,
                    'maintenance_margin_rate' => $liquidation->maintenanceMarginRate,
                    'maintenance_margin_deduction' => $liquidation->maintenanceMarginDeduction,
                    'position_size' => $shape['quantity'],
                ],
            ));

            return $check->isSafe ? [] : ['hyperliquid_liquidation_guard_unsafe'];
        } catch (\Throwable) {
            return ['hyperliquid_margin_evidence_unavailable'];
        }
    }

    /** @return list<string> */
    private function initialReasons(ExecutionRequest $request, ?ExchangeReadinessReport $report): array
    {
        $plan = $request->orderPlan;
        $reasons = [];
        $request->mode === ExecutionMode::Live || $reasons[] = 'live_execution_mode_required';
        $plan->validation->isExecutable || $reasons[] = 'order_plan_not_executable';
        strtolower($plan->exchange) === 'hyperliquid' || $reasons[] = 'hyperliquid_exchange_required';
        strtolower($plan->marketType) === 'perpetual' || $reasons[] = 'perpetual_market_required';
        strtolower($plan->orderType) === 'limit' || $reasons[] = 'limit_entry_required';
        strtolower(trim($plan->timeInForce)) === 'gtc' || $reasons[] = 'gtc_time_in_force_required';
        in_array(strtolower($plan->side), ['long', 'short'], true) || $reasons[] = 'position_side_invalid';
        $plan->protectionPlan?->stopLoss !== null || $reasons[] = 'stop_loss_required';
        $plan->protectionPlan?->stopLoss?->isFullSize === true || $reasons[] = 'full_size_stop_loss_required';

        if (!$report instanceof ExchangeReadinessReport) {
            $reasons[] = 'readiness_report_unavailable';
            return array_values(array_unique($reasons));
        }
        $reasons = array_merge($reasons, $report->blockingErrors, $this->readinessGate->blockingReasons($report, $this->config));
        $plan->profile === $report->configProfile || $reasons[] = 'effective_config_profile_mismatch';
        if (!is_string($plan->configHash)
            || preg_match('/^[a-f0-9]{64}$/D', $plan->configHash) !== 1
            || !is_string($report->configHash)
            || !hash_equals($report->configHash, $plan->configHash)
        ) {
            $reasons[] = 'effective_config_hash_mismatch';
        }
        if ($report->allowedSymbols !== [] && !in_array($plan->symbol, $report->allowedSymbols, true)) {
            $reasons[] = 'requested_symbol_not_allowed';
        }
        if ($report->allowedMarkets !== [] && !in_array($plan->marketType, $report->allowedMarkets, true)) {
            $reasons[] = 'requested_market_not_allowed';
        }
        $rawNotional = $plan->entryPrice * $plan->quantity;
        if (!is_finite($rawNotional) || $rawNotional <= 0.0
            || $report->maxNotional === null || $rawNotional > $report->maxNotional
        ) {
            $reasons[] = 'max_notional_exceeded';
        }
        try {
            if ($this->durableTrip->isTripped()) {
                $reasons[] = 'hyperliquid_durable_kill_switch_tripped';
            }
        } catch (\Throwable) {
            $reasons[] = 'hyperliquid_durable_kill_switch_unreadable';
        }

        return array_values(array_unique($reasons));
    }

    private function reportOrNull(): ?ExchangeReadinessReport
    {
        try {
            return $this->readiness->current();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function metadataReasons(?HyperliquidInstrumentMetadataDto $metadata, OrderPlan $plan): array
    {
        if (!($metadata instanceof HyperliquidInstrumentMetadataDto)) {
            return ['hyperliquid_metadata_missing'];
        }
        $reasons = [];
        $metadata->symbol === $plan->symbol || $reasons[] = 'hyperliquid_metadata_symbol_mismatch';
        $metadata->assetId >= 0 || $reasons[] = 'hyperliquid_asset_id_invalid';
        $metadata->status === 'live' || $reasons[] = 'hyperliquid_market_not_live';
        $metadata->isCompleteForSizing() || $reasons[] = 'hyperliquid_metadata_incomplete';
        try {
            if ($metadata->contractSize === null || !BigDecimal::of($metadata->contractSize)->isEqualTo(BigDecimal::one())) {
                $reasons[] = 'hyperliquid_contract_size_must_equal_one';
            }
            $min = BigDecimal::of($metadata->minSize);
            $max = BigDecimal::of($metadata->maxSize);
            $step = BigDecimal::of($metadata->quantityStep);
            $tick = BigDecimal::of($metadata->priceTick);
            $maxLeverage = BigDecimal::of($metadata->maxLeverage);
            $hasNoPublishedMaximum = $metadata->maxSize === '0';
            if ($min->isLessThanOrEqualTo(BigDecimal::zero()) || (!$hasNoPublishedMaximum && $max->isLessThan($min))
                || $step->isLessThanOrEqualTo(BigDecimal::zero()) || $tick->isLessThanOrEqualTo(BigDecimal::zero())
                || $maxLeverage->isLessThan(BigDecimal::of($plan->leverage))
                || $metadata->priceMaxDecimals < 0 || $metadata->priceMaxDecimals > 8
            ) {
                $reasons[] = 'hyperliquid_metadata_limits_invalid';
            }
        } catch (\Throwable) {
            $reasons[] = 'hyperliquid_metadata_limits_invalid';
        }

        return array_values(array_unique($reasons));
    }

    /** @return array{price:string,quantity:string,stop:string,reasons:list<string>} */
    private function shape(HyperliquidInstrumentMetadataDto $metadata, OrderPlan $plan): array
    {
        $price = $this->decimal($plan->entryPrice);
        $quantity = $this->decimal($plan->quantity);
        $stop = $this->decimal($plan->protectionPlan?->stopLoss?->stopPrice ?? 0.0);
        $entryShape = $metadata->validateOrderShape($price, $quantity);
        $stopShape = $metadata->validateOrderShape($stop, $quantity);
        $reasons = array_values(array_unique(array_merge($entryShape['quality_flags'], $stopShape['quality_flags'])));
        if (!$entryShape['price_valid'] || !$entryShape['quantity_valid'] || !$stopShape['price_valid']) {
            $reasons[] = 'hyperliquid_risk_changing_quantization_rejected';
        }
        try {
            $quantityValue = BigDecimal::of($entryShape['quantity_quantized']);
            if ($quantityValue->isLessThan(BigDecimal::of($metadata->minSize))) {
                $reasons[] = 'hyperliquid_quantity_below_minimum';
            }
            if ($metadata->maxSize !== '0' && $quantityValue->isGreaterThan(BigDecimal::of($metadata->maxSize))) {
                $reasons[] = 'hyperliquid_quantity_above_maximum';
            }
            $stopVsEntry = BigDecimal::of($stopShape['price_quantized'])->compareTo(BigDecimal::of($entryShape['price_quantized']));
            if (($plan->side === 'long' && $stopVsEntry >= 0) || ($plan->side === 'short' && $stopVsEntry <= 0)) {
                $reasons[] = 'hyperliquid_stop_not_protective';
            }
        } catch (\Throwable) {
            $reasons[] = 'hyperliquid_order_decimal_invalid';
        }

        return [
            'price' => $entryShape['price_quantized'],
            'quantity' => $entryShape['quantity_quantized'],
            'stop' => $stopShape['price_quantized'],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function updateLeverage(
        int $assetId,
        OrderPlan $plan,
        HyperliquidNonceScope $scope,
        string $correlationId,
    ): ?ExecutionResult {
        if (!$this->nonceReady($scope)) {
            return $this->rejected($plan, 'hyperliquid_nonce_not_ready', ['hyperliquid_nonce_store_not_ready']);
        }
        try {
            $nonce = $this->nonces->nextNonce($scope);
            $result = $this->signedActions->submit(
                $this->actions->updateLeverage($assetId, $plan->leverage, $plan->marginMode),
                $nonce,
                $correlationId,
            );
        } catch (\Throwable) {
            return $this->tripAndFail($plan, $correlationId, 'hyperliquid_leverage_update_ambiguous');
        }

        if ($result->actionType === 'updateLeverage' && $result->outcome === 'accepted'
            && $result->statuses === [] && $result->reason === null && hash_equals($correlationId, $result->correlationId)
        ) {
            return null;
        }
        if ($result->actionType === 'updateLeverage' && $result->outcome === 'rejected'
            && hash_equals($correlationId, $result->correlationId)
        ) {
            return $this->rejected($plan, 'hyperliquid_leverage_update_rejected', ['hyperliquid_leverage_update_rejected']);
        }

        return $this->tripAndFail($plan, $correlationId, 'hyperliquid_leverage_update_ambiguous');
    }

    private function mapSubmission(
        HyperliquidSignedActionResult $submission,
        OrderPlan $plan,
        HyperliquidInstrumentMetadataDto $metadata,
        HyperliquidExecutionState $state,
        HyperliquidNonceScope $scope,
        string $correlationId,
        string $quantity,
    ): ExecutionResult {
        $statuses = $submission->statuses;
        if ($submission->actionType === 'order' && hash_equals($correlationId, $submission->correlationId)
            && $submission->outcome === 'accepted' && count($statuses) === 2
            && $this->acceptedOrderRow($statuses[0]) && $this->acceptedOrderRow($statuses[1])
            && $statuses[0]['oid'] !== $statuses[1]['oid']
            && $this->filledSizeMatches($statuses[0], $quantity, $metadata->quantityStep)
            && $this->filledSizeMatches($statuses[1], $quantity, $metadata->quantityStep)
        ) {
            return $this->result(
                ExecutionStatus::Accepted,
                $plan,
                (string) $statuses[0]['oid'],
                ['outcome' => 'accepted', 'protection_confirmed' => true, 'correlation_id' => $correlationId],
            );
        }
        if ($submission->actionType === 'order' && hash_equals($correlationId, $submission->correlationId)
            && $submission->outcome === 'rejected'
            && count($statuses) === 2 && $this->errorRow($statuses[0]) && $this->errorRow($statuses[1])
        ) {
            return $this->result(
                ExecutionStatus::Rejected,
                $plan,
                null,
                ['outcome' => 'rejected', 'protection_confirmed' => false, 'correlation_id' => $correlationId],
            );
        }

        $entryOid = isset($statuses[0]) && $this->acceptedOrderRow($statuses[0]) ? (string) $statuses[0]['oid'] : null;

        return $this->compensate($plan, $metadata, $state, $scope, $correlationId, $entryOid);
    }

    private function compensate(
        OrderPlan $plan,
        HyperliquidInstrumentMetadataDto $metadata,
        HyperliquidExecutionState $state,
        HyperliquidNonceScope $scope,
        string $correlationId,
        ?string $entryOid,
    ): ExecutionResult {
        $quantityPrecision = $this->decimalPlaces($metadata->quantityStep);
        try {
            $compensation = $this->compensation->compensate(new HyperliquidCompensationContext(
                accountAddress: $scope->accountAddress,
                assetId: $metadata->assetId,
                symbol: $plan->symbol,
                positionSide: $this->positionSide($plan),
                entryWireCloid: $this->actions->cloid($plan->clientOrderId ?? ''),
                entryExchangeOrderId: $entryOid,
                quantity: (float) $this->decimal($plan->quantity),
                quantityPrecision: $quantityPrecision,
                quantityStep: $metadata->quantityStep,
                closeClientOrderId: $this->actions->cloid(($plan->clientOrderId ?? '') . ':emergency-close'),
                nonceScope: $scope,
                correlationId: $correlationId,
                marginMode: $plan->marginMode,
                leverage: $plan->leverage,
                emergencyCloseSlippageCapPrice: $this->executionStatePolicy->emergencyCloseCap($state, $plan->side, $metadata->priceTick),
                redactedAuditContext: ['correlation_id' => $correlationId, 'profile' => $plan->profile],
            ));
        } catch (\Throwable) {
            return $this->tripAndFail($plan, $correlationId, 'hyperliquid_compensation_failed');
        }

        if ($compensation->outcome === 'unknown_requires_resync') {
            $this->durableTrip->trip('hyperliquid_compensation_unconfirmed', ['correlation_id' => $correlationId]);
        }
        $status = $compensation->outcome === 'entry_rejected' ? ExecutionStatus::Rejected : ExecutionStatus::Failed;

        return $this->result($status, $plan, $compensation->entryExchangeOrderId, [
            'outcome' => $compensation->outcome,
            'compensation_outcome' => $compensation->outcome,
            'compensation_reason' => $compensation->reasonCode->value,
            'protection_confirmed' => false,
            'correlation_id' => $correlationId,
        ]);
    }

    private function entryRequest(OrderPlan $plan, string $price, string $quantity): PlaceOrderRequest
    {
        $position = $this->positionSide($plan);
        return new PlaceOrderRequest(
            Exchange::HYPERLIQUID,
            MarketType::PERPETUAL,
            $plan->symbol,
            $position === ExchangePositionSide::LONG ? ExchangeOrderSide::BUY : ExchangeOrderSide::SELL,
            $position,
            ExchangeOrderType::LIMIT,
            ExchangeTimeInForce::GTC,
            (float) $quantity,
            (float) $price,
            null,
            false,
            false,
            $plan->leverage,
            $plan->marginMode,
            $plan->clientOrderId ?? '',
        );
    }

    private function stopRequest(OrderPlan $plan, string $stop, string $quantity, string $tick): PlaceOrderRequest
    {
        $position = $this->positionSide($plan);
        return new PlaceOrderRequest(
            Exchange::HYPERLIQUID,
            MarketType::PERPETUAL,
            $plan->symbol,
            $position === ExchangePositionSide::LONG ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY,
            $position,
            ExchangeOrderType::STOP_LOSS,
            ExchangeTimeInForce::GTC,
            (float) $quantity,
            $this->executionStatePolicy->protectiveStopCap((float) $stop, $plan->side, $tick),
            (float) $stop,
            true,
            false,
            $plan->leverage,
            $plan->marginMode,
            ($plan->clientOrderId ?? '') . ':stop',
        );
    }

    private function positionSide(OrderPlan $plan): ExchangePositionSide
    {
        return strtolower($plan->side) === 'long' ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
    }

    /** @param array<string,mixed> $row */
    private function acceptedOrderRow(array $row): bool
    {
        return in_array($row['kind'] ?? null, ['resting', 'filled'], true)
            && isset($row['oid']) && is_int($row['oid']) && $row['oid'] > 0;
    }

    /** @param array<string,mixed> $row */
    private function filledSizeMatches(array $row, string $quantity, string $quantityStep): bool
    {
        if (($row['kind'] ?? null) !== 'filled') {
            return true;
        }
        if (!isset($row['total_size']) || !is_string($row['total_size'])) {
            return false;
        }

        try {
            $precision = $this->decimalPlaces($quantityStep);
            $totalSize = BigDecimal::of($row['total_size'])->toScale($precision);

            return $totalSize->remainder(BigDecimal::of($quantityStep))->isZero()
                && $totalSize->isEqualTo(BigDecimal::of($quantity));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $row */
    private function errorRow(array $row): bool
    {
        return $row === ['kind' => 'error'];
    }

    private function nonceScope(): HyperliquidNonceScope
    {
        return new HyperliquidNonceScope(
            environment: 'testnet',
            network: 'testnet',
            accountAddress: $this->config->signingAccountAddress(),
            signerAddress: $this->config->signerAddress(),
        );
    }

    private function nonceReady(HyperliquidNonceScope $scope): bool
    {
        try {
            return $this->nonces->isReady($scope);
        } catch (\Throwable) {
            return false;
        }
    }

    private function correlationId(ExecutionRequest $request): string
    {
        $candidate = $request->metadata['correlation_id'] ?? null;
        if (is_string($candidate) && (new HyperliquidCorrelationIdValidator())->isValid($candidate)) {
            return $candidate;
        }

        return 'hl-' . substr(hash('sha256', (string) $request->orderPlan->clientOrderId), 0, 24);
    }

    private function decimal(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('hyperliquid_decimal_invalid');
        }
        $rounded = sprintf('%.8F', $value);
        if (abs((float) $rounded - $value) >= 1.0e-12) {
            throw new \InvalidArgumentException('hyperliquid_decimal_not_representable');
        }

        return rtrim(rtrim($rounded, '0'), '.');
    }

    private function decimalPlaces(string $value): int
    {
        return str_contains($value, '.') ? strlen(rtrim(substr($value, strpos($value, '.') + 1), '0')) : 0;
    }

    private function milliseconds(\DateTimeInterface $time): int
    {
        return ((int) $time->format('U') * 1_000) + (int) $time->format('v');
    }

    /** @param list<string> $reasons */
    private function rejected(OrderPlan $plan, string $reason, array $reasons): ExecutionResult
    {
        return $this->result(ExecutionStatus::Rejected, $plan, null, [
            'reject_reason' => $reason,
            'blocking_reasons' => array_values(array_unique($reasons)),
            'protection_confirmed' => false,
        ]);
    }

    private function tripAndFail(OrderPlan $plan, string $correlationId, string $reason): ExecutionResult
    {
        $this->durableTrip->trip($reason, ['correlation_id' => $correlationId]);

        return $this->failed($plan, $correlationId, $reason);
    }

    private function failed(OrderPlan $plan, string $correlationId, string $reason): ExecutionResult
    {
        return $this->result(ExecutionStatus::Failed, $plan, null, [
            'outcome' => 'unknown_requires_resync',
            'failure_reason' => $reason,
            'protection_confirmed' => false,
            'correlation_id' => $correlationId,
        ]);
    }

    /** @param array<string,mixed> $metadata */
    private function result(ExecutionStatus $status, OrderPlan $plan, ?string $oid, array $metadata): ExecutionResult
    {
        return new ExecutionResult(
            status: $status,
            clientOrderId: $plan->clientOrderId,
            exchangeOrderId: $oid,
            raw: [],
            metadata: $this->redactMetadata($metadata),
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function redactMetadata(array $metadata): array
    {
        $redactor = new HyperliquidKillSwitchAuditSanitizer();
        $walk = function (mixed $value, ?string $key = null) use (&$walk, $redactor): mixed {
            if ($key !== null && preg_match('/(?:raw|payload|actions?|responses?|statuses|secret|token|api[_-]?key|private[_-]?key|passphrase|password|authorization|cookie|signature|credential|memo)/i', $key) === 1) {
                return null;
            }
            if (is_array($value)) {
                $result = [];
                foreach ($value as $childKey => $childValue) {
                    $redacted = $walk($childValue, is_string($childKey) ? $childKey : null);
                    if ($redacted !== null || $childValue === null) {
                        $result[$childKey] = $redacted;
                    }
                }
                return $result;
            }
            if (is_string($value) && !$redactor->isSafeOpaqueValue($value)) {
                return '[redacted]';
            }
            if (is_float($value) && !is_finite($value)) {
                return null;
            }
            return $value;
        };

        /** @var array<string,mixed> $redacted */
        $redacted = $walk($metadata);
        return $redacted;
    }
}
