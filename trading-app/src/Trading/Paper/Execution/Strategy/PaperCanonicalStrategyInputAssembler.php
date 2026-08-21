<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Common\Enum\Timeframe;
use App\Trading\Lineage\LineageContextException;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\MarketData\CanonicalIndicatorSnapshotIdentity;
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
        if (!$this->isExactTrigger($event, $executionTimeframe, $request)) {
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

    private function isExactTrigger(
        PaperMarketEvent $event,
        string $timeframe,
        ShadowRuntimeRequest $request,
    ): bool {
        $channel = match ($timeframe) {
            '1m' => PaperMarketDataChannel::CANDLE_1M,
            '5m' => PaperMarketDataChannel::CANDLE_5M,
            '15m' => PaperMarketDataChannel::CANDLE_15M,
            '1h' => PaperMarketDataChannel::CANDLE_1H,
            default => null,
        };
        $declared = $event->payload['interval'] ?? $event->payload['bar'] ?? null;
        $snapshot = $request->indicatorsByTimeframe[$timeframe] ?? null;
        if (!is_array($snapshot)) {
            return false;
        }
        $identityData = $snapshot['snapshot_identity'] ?? null;
        $identity = is_array($identityData) && !array_is_list($identityData)
            ? CanonicalIndicatorSnapshotIdentity::tryFromArray($identityData)
            : null;
        $eventOpen = $this->eventCandleOpenSecond($event, $timeframe);
        $snapshotOpen = $this->canonicalSecond($snapshot['kline_time'] ?? null);

        return $channel !== null
            && $event->channel === $channel
            && ($event->payload['confirmed'] ?? null) === true
            && is_string($declared)
            && strtolower($declared) === $timeframe
            && $identity !== null
            && $identity->matches(
                $timeframe,
                $event->symbol,
                $event->sourceVenue->value,
                $event->sourceNetwork->value,
                'perpetual',
            )
            && $eventOpen !== null
            && $eventOpen === $snapshotOpen;
    }

    private function eventCandleOpenSecond(PaperMarketEvent $event, string $timeframe): ?int
    {
        $value = $event->payload['start_time'] ?? null;
        if (!is_string($value) || preg_match('/\A[0-9]{13}\z/D', $value) !== 1) {
            return null;
        }
        $milliseconds = (int) $value;
        $timeframeValue = Timeframe::tryFrom($timeframe);
        if ($milliseconds % 1000 !== 0
            || $timeframeValue === null
            || intdiv($milliseconds, 1000) % $timeframeValue->getStepInSeconds() !== 0
        ) {
            return null;
        }

        return intdiv($milliseconds, 1000);
    }

    private function canonicalSecond(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            $instant = \DateTimeImmutable::createFromInterface($value);

            return $instant->format('u') === '000000' ? $instant->getTimestamp() : null;
        }
        if (is_int($value)) {
            return $value > 10_000_000_000 && $value % 1000 === 0
                ? intdiv($value, 1000)
                : ($value <= 10_000_000_000 ? $value : null);
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $seconds = $value > 10_000_000_000 ? $value / 1000.0 : $value;

            return floor($seconds) === $seconds ? (int) $seconds : null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $instant = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $instant->format('u') === '000000' ? $instant->getTimestamp() : null;
    }
}
