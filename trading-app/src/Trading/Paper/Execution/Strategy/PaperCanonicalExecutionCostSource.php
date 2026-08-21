<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Exchange\Fake\FakeFillCostModel;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionCostSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanTime;
use App\TradingCore\OrderPlan\Canonical\CanonicalTargetCostSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalTargetPolicy;

final readonly class PaperCanonicalExecutionCostSource
{
    public function __construct(
        private PaperCanonicalOrderBookSource $books,
        private PaperCanonicalFundingSource $funding,
        private PaperReplayClock $clock,
        private FakeFillCostModel $fillCosts = new FakeFillCostModel(),
    ) {
    }

    public function snapshotFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        CanonicalExecutionPolicy $policy,
    ): ?CanonicalExecutionCostSnapshot {
        $this->assertIdentity($cell, $trigger, $policy);
        $this->assertContract($policy);

        $book = $this->books->snapshotFor($cell, $trigger);
        if ($book === null) {
            return null;
        }
        $funding = $this->funding->snapshotFor(
            $cell,
            $trigger,
            $policy->costContract->fundingIntervalSeconds,
        );
        if ($funding === null) {
            return null;
        }

        $this->assertBook($cell, $trigger, $policy, $book);
        if ($funding->source !== $policy->costContract->fundingSource
            || $funding->intervalSeconds !== $policy->costContract->fundingIntervalSeconds
            || $funding->observedAt > $this->clock->now()
        ) {
            throw new \LogicException('paper_canonical_execution_cost_funding_invalid');
        }

        $spreadRate = $book->spreadBps / 10_000.0;
        $entrySlippage = $this->slippageRate((string) $policy->costContract->entryLiquidityRole);
        $stopSlippage = $this->slippageRate((string) $policy->costContract->stopLiquidityRole);
        $targets = array_map(
            fn (CanonicalTargetPolicy $target): CanonicalTargetCostSnapshot => new CanonicalTargetCostSnapshot(
                $target->id,
                $policy->costContract->targetSpreadSource,
                $spreadRate,
                $policy->costContract->targetSlippageSource,
                $this->slippageRate($target->liquidityRole),
            ),
            $policy->targets,
        );

        return new CanonicalExecutionCostSnapshot(
            exchange: $book->exchange,
            environment: $book->environment,
            symbol: $book->symbol,
            marketType: $book->marketType,
            configHash: $policy->configHash,
            entryLiquidityRole: $policy->costContract->entryLiquidityRole,
            stopLiquidityRole: $policy->costContract->stopLiquidityRole,
            entrySpreadSource: $policy->costContract->entrySpreadSource,
            entrySpreadRate: $spreadRate,
            entrySlippageSource: $policy->costContract->entrySlippageSource,
            entrySlippageRate: $entrySlippage,
            stopSpreadSource: $policy->costContract->stopSpreadSource,
            stopSpreadRate: $spreadRate,
            stopSlippageSource: $policy->costContract->stopSlippageSource,
            stopSlippageRate: $stopSlippage,
            fundingSource: $policy->costContract->fundingSource,
            fundingRate: $funding->rate,
            targets: $targets,
            observedAt: $book->observedAt,
            inputHash: 'sha256:' . hash('sha256', CanonicalJson::encode($this->hashPayload(
                $cell,
                $trigger,
                $policy,
                $book,
                $funding,
                $spreadRate,
                $entrySlippage,
                $stopSlippage,
                $targets,
            ))),
        );
    }

    private function assertIdentity(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        CanonicalExecutionPolicy $policy,
    ): void {
        $identity = $cell->modernIdentity;
        if ($identity === null) {
            throw new \LogicException('paper_canonical_strategy_cell_identity_missing');
        }
        if ($trigger->sourceNetwork !== $cell->network || $trigger->sourceVenue !== $cell->marketDataVenue) {
            throw new \LogicException('paper_canonical_strategy_market_scope_mismatch');
        }

        $risk = $policy->riskPolicy;
        if ($risk->modeId !== $identity->modeId
            || $risk->modeVersion !== $identity->modeVersion
            || $risk->setupId !== $identity->setupId
            || $risk->setupVersion !== $identity->setupVersion
            || $risk->side !== $identity->side
            || $risk->exchange !== $cell->marketDataVenue->value
            || $risk->environment !== $cell->network->value
            || !hash_equals($risk->configHash, $identity->configHash)
            || !hash_equals($policy->configHash, $identity->configHash)
            || !\in_array($trigger->symbol, $policy->allowedSymbols, true)
            || !\in_array('perpetual', $policy->allowedMarkets, true)
        ) {
            throw new \LogicException('paper_canonical_execution_cost_identity_mismatch');
        }
    }

    private function assertContract(CanonicalExecutionPolicy $policy): void
    {
        $contract = $policy->costContract;
        $order = $policy->orderPolicy;
        if ($order === null
            || $contract->entryLiquidityRole !== $order->liquidityRole
            || !\in_array($contract->entryLiquidityRole, ['maker', 'taker'], true)
            || !\in_array($contract->stopLiquidityRole, ['maker', 'taker'], true)
            || $contract->entrySpreadSource !== 'order_book'
            || $contract->stopSpreadSource !== 'order_book'
            || $contract->targetSpreadSource !== 'order_book'
            || $contract->entrySlippageSource !== 'execution_model'
            || $contract->stopSlippageSource !== 'execution_model'
            || $contract->targetSlippageSource !== 'execution_model'
            || $contract->fundingSource !== 'venue_schedule'
            || $contract->fundingIntervalSeconds < 1
            || $policy->targets === []
        ) {
            throw new \LogicException('paper_canonical_execution_cost_contract_mismatch');
        }

        $targetIds = [];
        foreach ($policy->targets as $target) {
            if (isset($targetIds[$target->id]) || !\in_array($target->liquidityRole, ['maker', 'taker'], true)) {
                throw new \LogicException('paper_canonical_execution_cost_contract_mismatch');
            }
            $targetIds[$target->id] = true;
        }
    }

    private function assertBook(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        CanonicalExecutionPolicy $policy,
        CanonicalOrderBookSnapshot $book,
    ): void {
        $contract = $policy->costContract;
        if ($book->exchange !== $cell->marketDataVenue->value
            || $book->environment !== $cell->network->value
            || $book->symbol !== $trigger->symbol
            || $book->marketType !== 'perpetual'
            || $book->source !== $contract->entrySpreadSource
            || $book->source !== $contract->stopSpreadSource
            || $book->source !== $contract->targetSpreadSource
            || !\is_finite($book->spreadBps)
            || $book->spreadBps < 0.0
            || $book->spreadBps >= 10_000.0
        ) {
            throw new \LogicException('paper_canonical_execution_cost_book_invalid');
        }
        $now = $this->clock->now();
        if ($book->observedAt > $now) {
            throw new \LogicException('paper_canonical_execution_cost_book_future');
        }
        if (CanonicalOrderPlanTime::isOlderThan(
            $book->observedAt,
            $now,
            $policy->entryZone->maximumInputAgeSeconds,
        )) {
            throw new \LogicException('paper_canonical_execution_cost_book_stale');
        }
    }

    private function slippageRate(string $liquidityRole): float
    {
        if (!\in_array($liquidityRole, ['maker', 'taker'], true)) {
            throw new \LogicException('paper_canonical_execution_cost_contract_mismatch');
        }
        $cost = $this->fillCosts->forFill(1.0, 1.0, 1.0, $liquidityRole === 'maker');
        if ($cost->liquidityRole !== $liquidityRole
            || $cost->spreadCostUsdt !== 0.0
            || $cost->modelVersion !== FakeFillCostModel::MODEL_VERSION
            || $cost->spreadModelVersion !== FakeFillCostModel::SPREAD_MODEL_VERSION
            || !\is_finite($cost->slippageCostUsdt)
            || $cost->slippageCostUsdt < 0.0
            || $cost->slippageCostUsdt >= 1.0
        ) {
            throw new \LogicException('paper_canonical_execution_cost_model_mismatch');
        }

        return $cost->slippageCostUsdt;
    }

    /**
     * @param list<CanonicalTargetCostSnapshot> $targets
     * @return array<string, mixed>
     */
    private function hashPayload(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        CanonicalExecutionPolicy $policy,
        CanonicalOrderBookSnapshot $book,
        PaperCanonicalFundingSnapshot $funding,
        float $spreadRate,
        float $entrySlippage,
        float $stopSlippage,
        array $targets,
    ): array {
        return [
            'schema_version' => 'paper-canonical-execution-cost.v1',
            'cell_id' => $cell->id,
            'trigger_event_id' => $trigger->eventId,
            'config_hash' => $policy->configHash,
            'identity' => [
                'network' => $cell->network->value,
                'market_data_venue' => $cell->marketDataVenue->value,
                'symbol' => $trigger->symbol,
                'market_type' => 'perpetual',
            ],
            'fees' => [
                'maker_rate' => $policy->riskPolicy->makerFeeRate,
                'taker_rate' => $policy->riskPolicy->takerFeeRate,
            ],
            'book' => [
                'source' => $book->source,
                'spread_bps' => $book->spreadBps,
                'spread_rate' => $spreadRate,
                'observed_at' => $book->observedAt->format('Y-m-d\TH:i:s.u\Z'),
                'input_hash' => $book->inputHash,
            ],
            'funding' => [
                'source' => $funding->source,
                'rate' => $funding->rate,
                'interval_seconds' => $funding->intervalSeconds,
                'observed_at' => $funding->observedAt->format('Y-m-d\TH:i:s.u\Z'),
                'input_hash' => $funding->inputHash,
            ],
            'execution_model' => [
                'slippage_model' => FakeFillCostModel::MODEL_VERSION,
                'spread_model' => FakeFillCostModel::SPREAD_MODEL_VERSION,
                'taker_slippage_bps' => FakeFillCostModel::TAKER_SLIPPAGE_BPS,
            ],
            'entry' => [
                'liquidity_role' => $policy->costContract->entryLiquidityRole,
                'spread_source' => $policy->costContract->entrySpreadSource,
                'spread_rate' => $spreadRate,
                'slippage_source' => $policy->costContract->entrySlippageSource,
                'slippage_rate' => $entrySlippage,
            ],
            'stop' => [
                'liquidity_role' => $policy->costContract->stopLiquidityRole,
                'spread_source' => $policy->costContract->stopSpreadSource,
                'spread_rate' => $spreadRate,
                'slippage_source' => $policy->costContract->stopSlippageSource,
                'slippage_rate' => $stopSlippage,
            ],
            'targets' => array_map(
                static fn (CanonicalTargetCostSnapshot $target): array => [
                    'target_id' => $target->targetId,
                    'spread_source' => $target->spreadSource,
                    'spread_rate' => $target->spreadRate,
                    'slippage_source' => $target->slippageSource,
                    'slippage_rate' => $target->slippageRate,
                ],
                $targets,
            ),
        ];
    }
}
