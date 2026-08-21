<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Lineage\LineageContextException;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Shadow\ShadowRuntimeRequest;

final readonly class PaperCanonicalStrategyInputAssembler implements PaperCanonicalStrategyInputAssemblerInterface
{
    public function __construct(
        private PaperCanonicalStrategyEvidenceProviderInterface $evidence,
    ) {
    }

    public function assemble(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
    ): ?PaperCanonicalStrategyInput {
        $identity = $cell->modernIdentity;
        if ($identity === null) {
            throw new \LogicException('paper_canonical_strategy_cell_identity_missing');
        }
        if ($event->sourceNetwork !== $cell->network || $event->sourceVenue !== $cell->marketDataVenue) {
            throw new \LogicException('paper_canonical_strategy_market_scope_mismatch');
        }

        $evidence = $this->evidence->evidenceFor($cell, $event);
        if ($evidence === null) {
            return null;
        }
        $request = $evidence->toRuntimeRequest();

        try {
            $request->lineage->assertCanonicalIntegrity()->assertExecutableTradeContract();
        } catch (LineageContextException $exception) {
            throw new \LogicException('paper_canonical_strategy_input_identity_mismatch', 0, $exception);
        }

        if (!$this->identityMatches($cell, $event, $request)) {
            throw new \LogicException('paper_canonical_strategy_input_identity_mismatch');
        }

        $executionTimeframe = $this->executionTimeframe($request);
        if (!$this->isExactTrigger($event, $executionTimeframe)) {
            throw new \LogicException('paper_canonical_strategy_trigger_mismatch');
        }

        return new PaperCanonicalStrategyInput($request, $executionTimeframe);
    }

    private function identityMatches(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        ShadowRuntimeRequest $request,
    ): bool {
        $identity = $cell->modernIdentity;
        if ($identity === null) {
            return false;
        }
        $config = $request->configRequest;
        $lineage = $request->lineage;
        $plan = $request->orderPlanRequest;
        $risk = $plan->policy->riskPolicy;
        $scope = $request->portfolioScope;
        $snapshot = $lineage->effectiveConfigSnapshot?->toArray();

        return $config->capability === ShadowExecutionCapability::Paper
            && $config->modeId === $identity->modeId
            && $config->modeVersion === $identity->modeVersion
            && $config->setupId === $identity->setupId
            && $config->setupVersion === $identity->setupVersion
            && $config->side === $identity->side
            && $config->exchange === $cell->marketDataVenue->value
            && $config->environment === $cell->network->value
            && $lineage->modeId === $identity->modeId
            && $lineage->modeVersion === $identity->modeVersion
            && $lineage->setupId === $identity->setupId
            && $lineage->setupVersion === $identity->setupVersion
            && strtolower((string) $lineage->side) === $identity->side
            && $lineage->configHash === $identity->configHash
            && $lineage->conditionCatalogHash === $identity->conditionCatalogHash
            && $lineage->exchange === $cell->marketDataVenue->value
            && $lineage->environment === $cell->network->value
            && $lineage->marketType === 'perpetual'
            && $lineage->symbol === $event->symbol
            && $lineage->orchestrationRunId === $cell->runId
            && $lineage->decisionKey === $request->decisionKey
            && $lineage->dryRun === true
            && is_array($snapshot)
            && ($snapshot['config_hash'] ?? null) === $identity->configHash
            && ($snapshot['condition_catalog_hash'] ?? null) === $identity->conditionCatalogHash
            && ($snapshot['request'] ?? null) == $config->toArray()
            && $risk->modeId === $identity->modeId
            && $risk->modeVersion === $identity->modeVersion
            && $risk->setupId === $identity->setupId
            && $risk->setupVersion === $identity->setupVersion
            && $risk->side === $identity->side
            && $risk->exchange === $cell->marketDataVenue->value
            && $risk->environment === $cell->network->value
            && $plan->policy->configHash === $identity->configHash
            && $plan->zone->symbol === $event->symbol
            && $plan->zone->marketType === 'perpetual'
            && $scope == $request->portfolioSnapshot->scope
            && $scope->network === $cell->network->value
            && $scope->exchange === $cell->marketDataVenue->value
            && $scope->environment === $cell->network->value
            && $scope->accountId === $cell->accountNamespace
            && $scope->modeId === $identity->modeId;
    }

    private function executionTimeframe(ShadowRuntimeRequest $request): string
    {
        $config = $request->lineage->effectiveConfigSnapshot?->config();
        $decision = is_array($config)
            ? ($config['setup']['ast']['execution']['execution_timeframe'] ?? null)
            : null;
        $timeframe = is_array($decision) && ($decision['state'] ?? null) === 'defined'
            ? ($decision['value'] ?? null)
            : null;
        if (!is_string($timeframe) || !in_array($timeframe, ['1m', '5m', '15m', '1h', '4h'], true)) {
            throw new \LogicException('paper_canonical_strategy_execution_timeframe_invalid');
        }

        return $timeframe;
    }

    private function isExactTrigger(PaperMarketEvent $event, string $timeframe): bool
    {
        $channel = match ($timeframe) {
            '1m' => PaperMarketDataChannel::CANDLE_1M,
            '5m' => PaperMarketDataChannel::CANDLE_5M,
            '15m' => PaperMarketDataChannel::CANDLE_15M,
            '1h' => PaperMarketDataChannel::CANDLE_1H,
            default => null,
        };
        $declared = $event->payload['interval'] ?? $event->payload['bar'] ?? null;

        return $channel !== null
            && $event->channel === $channel
            && ($event->payload['confirmed'] ?? null) === true
            && is_string($declared)
            && strtolower($declared) === $timeframe;
    }
}
