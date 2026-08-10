<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalOrderPlan
{
    /**
     * @param non-empty-list<CanonicalOrderPlanTarget> $targets
     * @param non-empty-list<string>                  $inputHashes
     * @param list<string>                            $capsApplied
     */
    private function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $symbol,
        public string $side,
        public string $orderType,
        public float $quantity,
        public float $quantityStep,
        public float $contractSize,
        public float $entryPrice,
        public float $stopPrice,
        public float $tickSize,
        public float $zoneLowerPrice,
        public float $zoneUpperPrice,
        public array $targets,
        public float $minimumNetR,
        public float $riskBudgetQuote,
        public float $grossStopLoss,
        public float $totalStopLoss,
        public float $positionNotional,
        public int $finalLeverage,
        public int $effectiveLeverageCap,
        public float $modeLeverageCap,
        public float $exchangeMaxNotional,
        public float $environmentMaxNotional,
        public array $capsApplied,
        public float $entryFee,
        public float $stopExitFee,
        public float $entrySpreadCost,
        public float $stopSpreadCost,
        public float $entrySlippageCost,
        public float $stopSlippageCost,
        public float $fundingCost,
        public int $fundingIntervals,
        public \DateTimeImmutable $observedAt,
        public \DateTimeImmutable $zoneComputedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public string $configHash,
        public string $costInputHash,
        public array $inputHashes,
        public string $planHash,
    ) {
    }

    /**
     * @param non-empty-list<CanonicalOrderPlanTarget> $targets
     * @param non-empty-list<string>                  $inputHashes
     */
    public static function fromAcceptedComponents(
        CanonicalOrderPlanBuildRequest $request,
        array $targets,
        array $inputHashes,
        \DateTimeImmutable $createdAt,
    ): self {
        $policy = $request->policy;
        $riskPolicy = $policy->riskPolicy;
        $risk = $request->risk;
        $zone = $request->zone;
        $values = [
            'modeId' => $riskPolicy->modeId,
            'modeVersion' => $riskPolicy->modeVersion,
            'setupId' => $riskPolicy->setupId,
            'setupVersion' => $riskPolicy->setupVersion,
            'exchange' => $riskPolicy->exchange,
            'environment' => $riskPolicy->environment,
            'symbol' => $zone->symbol,
            'side' => $riskPolicy->side,
            'orderType' => 'limit',
            'quantity' => $risk->quantity,
            'quantityStep' => $risk->quantityStep,
            'contractSize' => $risk->contractSize,
            'entryPrice' => $zone->entryPrice,
            'stopPrice' => $request->protection->stopPrice,
            'tickSize' => $zone->tickSize,
            'zoneLowerPrice' => $zone->lowerPrice,
            'zoneUpperPrice' => $zone->upperPrice,
            'targets' => $targets,
            'minimumNetR' => $policy->minimumNetR,
            'riskBudgetQuote' => $risk->riskBudgetQuote,
            'grossStopLoss' => $risk->grossStopLoss,
            'totalStopLoss' => $risk->totalStopLoss,
            'positionNotional' => $risk->positionNotional,
            'finalLeverage' => $risk->finalLeverage,
            'effectiveLeverageCap' => $risk->effectiveLeverageCap,
            'modeLeverageCap' => $riskPolicy->modeLeverageCap,
            'exchangeMaxNotional' => $riskPolicy->exchangeMaxNotional,
            'environmentMaxNotional' => $riskPolicy->environmentMaxNotional,
            'capsApplied' => $risk->capsApplied,
            'entryFee' => $risk->entryFee,
            'stopExitFee' => $risk->stopExitFee,
            'entrySpreadCost' => $risk->entrySpreadCost,
            'stopSpreadCost' => $risk->stopSpreadCost,
            'entrySlippageCost' => $risk->entrySlippageCost,
            'stopSlippageCost' => $risk->stopSlippageCost,
            'fundingCost' => $risk->fundingCost,
            'fundingIntervals' => $request->netR->fundingIntervals,
            'observedAt' => $zone->observedAt,
            'zoneComputedAt' => $zone->computedAt,
            'createdAt' => $createdAt,
            'expiresAt' => $zone->expiresAt,
            'configHash' => $policy->configHash,
            'costInputHash' => $request->netR->costInputHash,
            'inputHashes' => $inputHashes,
        ];
        $planHash = self::hashValues($values);
        $values['planHash'] = $planHash;

        return new self(...$values);
    }

    public function expectedPlanHash(): string
    {
        $values = get_object_vars($this);
        unset($values['planHash']);

        return self::hashValues($values);
    }

    /** @param array<string, mixed> $values */
    private static function hashValues(array $values): string
    {
        $values['targets'] = array_map(
            static fn (CanonicalOrderPlanTarget $target): array => $target->toArray(),
            $values['targets'],
        );
        foreach (['observedAt', 'zoneComputedAt', 'createdAt', 'expiresAt'] as $field) {
            $timestamp = $values[$field];
            if (!$timestamp instanceof \DateTimeImmutable) {
                throw new CanonicalOrderPlanException('canonical_order_plan_timestamp_invalid');
            }
            $values[$field] = $timestamp->format('Y-m-d\TH:i:s.uP');
        }

        return 'sha256:' . hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }
}
