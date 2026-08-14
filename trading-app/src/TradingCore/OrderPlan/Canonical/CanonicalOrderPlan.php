<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Psr\Clock\ClockInterface;

final readonly class CanonicalOrderPlan
{
    private const WIRE_FIELDS = [
        'modeId', 'modeVersion', 'setupId', 'setupVersion', 'exchange',
        'environment', 'symbol', 'marketType', 'quoteCurrency', 'side',
        'orderType', 'marketFallback', 'quantity', 'quantityStep', 'contractSize',
        'entryPrice', 'stopPrice', 'tickSize', 'zoneLowerPrice', 'zoneUpperPrice',
        'targets', 'minimumNetR', 'equityQuote', 'availableBalanceQuote',
        'riskRate', 'riskBudgetQuote', 'grossStopLoss', 'totalStopLoss',
        'positionNotional', 'finalLeverage', 'effectiveLeverageCap',
        'modeLeverageCap', 'exchangeLeverageCap', 'symbolLeverageCap',
        'minQuantity', 'maxQuantity', 'marketMaxQuantity', 'exchangeMinNotional',
        'exchangeMaxNotional', 'environmentMaxNotional', 'capsApplied',
        'makerFeeRate', 'takerFeeRate', 'entryLiquidityRole', 'stopLiquidityRole',
        'entrySpreadRate', 'stopSpreadRate', 'entrySlippageRate',
        'stopSlippageRate', 'fundingRate', 'entryFee', 'stopExitFee',
        'entrySpreadCost', 'stopSpreadCost', 'entrySlippageCost',
        'stopSlippageCost', 'fundingCost', 'fundingIntervals',
        'maximumInputAgeSeconds', 'inputObservedAt', 'observedAt',
        'costObservedAt', 'zoneComputedAt', 'createdAt', 'expiresAt',
        'cancelAfterAt', 'holdingExpiresAt', 'configHash', 'costInputHash',
        'orderBookInputHash', 'inputHashes', 'planHash',
    ];

    private const OPTIONAL_WIRE_FIELDS = [
        'cancelAfterAt', 'holdingExpiresAt', 'orderBookInputHash',
    ];

    private const TARGET_FIELDS = [
        'id', 'price', 'riskMultiple', 'liquidityRole', 'spreadRate',
        'slippageRate', 'grossReward', 'entryFee', 'targetFee',
        'entrySpreadCost', 'entrySlippageCost', 'targetSpreadCost',
        'targetSlippageCost', 'fundingCost', 'netReward', 'netRisk', 'netR',
    ];

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
        public string $marketType,
        public string $quoteCurrency,
        public string $side,
        public string $orderType,
        public bool $marketFallback,
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
        public float $equityQuote,
        public float $availableBalanceQuote,
        public float $riskRate,
        public float $riskBudgetQuote,
        public float $grossStopLoss,
        public float $totalStopLoss,
        public float $positionNotional,
        public int $finalLeverage,
        public int $effectiveLeverageCap,
        public float $modeLeverageCap,
        public float $exchangeLeverageCap,
        public ?float $symbolLeverageCap,
        public float $minQuantity,
        public float $maxQuantity,
        public ?float $marketMaxQuantity,
        public float $exchangeMinNotional,
        public float $exchangeMaxNotional,
        public float $environmentMaxNotional,
        public array $capsApplied,
        public float $makerFeeRate,
        public float $takerFeeRate,
        public string $entryLiquidityRole,
        public string $stopLiquidityRole,
        public float $entrySpreadRate,
        public float $stopSpreadRate,
        public float $entrySlippageRate,
        public float $stopSlippageRate,
        public float $fundingRate,
        public float $entryFee,
        public float $stopExitFee,
        public float $entrySpreadCost,
        public float $stopSpreadCost,
        public float $entrySlippageCost,
        public float $stopSlippageCost,
        public float $fundingCost,
        public int $fundingIntervals,
        public int $maximumInputAgeSeconds,
        public \DateTimeImmutable $inputObservedAt,
        public \DateTimeImmutable $observedAt,
        public \DateTimeImmutable $costObservedAt,
        public \DateTimeImmutable $zoneComputedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $cancelAfterAt,
        public ?\DateTimeImmutable $holdingExpiresAt,
        public string $configHash,
        public string $costInputHash,
        public ?string $orderBookInputHash,
        public array $inputHashes,
        public string $planHash,
    ) {
    }

    public static function build(
        CanonicalOrderPlanBuildRequest $request,
        ClockInterface $clock,
        CanonicalOrderPlanValidator $validator,
        CanonicalOrderPlanAuthority $authority = new CanonicalOrderPlanAuthority(),
    ): self {
        $request = $authority->verify($request);
        $now = $clock->now();
        if ($request->zone->computedAt > $now || $request->zone->expiresAt < $now) {
            throw new CanonicalOrderPlanException('canonical_order_plan_expired');
        }
        if (
            $request->costs->observedAt > $now
            || CanonicalOrderPlanTime::isOlderThan(
                $request->costs->observedAt,
                $now,
                $request->policy->entryZone->maximumInputAgeSeconds,
            )
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_cost_stale');
        }
        if (
            $request->riskRequest->instrument->observedAt > $now
            || CanonicalOrderPlanTime::isOlderThan(
                $request->riskRequest->instrument->observedAt,
                $now,
                $request->policy->entryZone->maximumInputAgeSeconds,
            )
            || $request->protection->oldestObservedAt > $now
            || CanonicalOrderPlanTime::isOlderThan(
                $request->protection->oldestObservedAt,
                $now,
                $request->policy->entryZone->maximumInputAgeSeconds,
            )
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_input_stale');
        }

        return $validator->validate(self::fromAcceptedComponents($request, $now));
    }

    /** @param array<string, mixed> $wire */
    public static function fromArray(array $wire): self
    {
        self::assertWireShape($wire);
        $values = [];
        foreach (self::WIRE_FIELDS as $field) {
            $values[$field] = $wire[$field] ?? null;
        }

        try {
            foreach ([
                'modeId', 'modeVersion', 'setupId', 'setupVersion', 'exchange',
                'environment', 'symbol', 'marketType', 'quoteCurrency', 'side',
                'orderType', 'entryLiquidityRole', 'stopLiquidityRole',
                'configHash', 'costInputHash', 'planHash',
            ] as $field) {
                $values[$field] = self::wireString($values[$field]);
            }
            if (!\is_bool($values['marketFallback'])) {
                throw new CanonicalOrderPlanException('canonical_order_plan_wire_scalar_invalid');
            }
            foreach ([
                'quantity', 'quantityStep', 'contractSize', 'entryPrice',
                'stopPrice', 'tickSize', 'zoneLowerPrice', 'zoneUpperPrice',
                'minimumNetR', 'equityQuote', 'availableBalanceQuote', 'riskRate',
                'riskBudgetQuote', 'grossStopLoss', 'totalStopLoss',
                'positionNotional', 'modeLeverageCap', 'exchangeLeverageCap',
                'minQuantity', 'maxQuantity', 'exchangeMinNotional',
                'exchangeMaxNotional', 'environmentMaxNotional', 'makerFeeRate',
                'takerFeeRate', 'entrySpreadRate', 'stopSpreadRate',
                'entrySlippageRate', 'stopSlippageRate', 'fundingRate',
                'entryFee', 'stopExitFee', 'entrySpreadCost', 'stopSpreadCost',
                'entrySlippageCost', 'stopSlippageCost', 'fundingCost',
            ] as $field) {
                $values[$field] = self::wireFloat($values[$field]);
            }
            foreach (['symbolLeverageCap', 'marketMaxQuantity'] as $field) {
                $values[$field] = $values[$field] === null ? null : self::wireFloat($values[$field]);
            }
            foreach ([
                'finalLeverage', 'effectiveLeverageCap', 'fundingIntervals',
                'maximumInputAgeSeconds',
            ] as $field) {
                if (!\is_int($values[$field])) {
                    throw new CanonicalOrderPlanException('canonical_order_plan_wire_scalar_invalid');
                }
            }
            $values['capsApplied'] = self::stringList($values['capsApplied'], false);
            $values['inputHashes'] = self::stringList($values['inputHashes'], true);
            $values['targets'] = self::targets($values['targets']);
            foreach ([
                'inputObservedAt', 'observedAt', 'costObservedAt',
                'zoneComputedAt', 'createdAt', 'expiresAt',
            ] as $field) {
                $values[$field] = self::wireTime($values[$field]);
            }
            foreach (['cancelAfterAt', 'holdingExpiresAt'] as $field) {
                $values[$field] = $values[$field] === null ? null : self::wireTime($values[$field]);
            }
            if ($values['orderBookInputHash'] !== null) {
                $values['orderBookInputHash'] = self::wireString($values['orderBookInputHash']);
            }

            $plan = new self(...$values);

            return CanonicalOrderPlanValidator::validateAt($plan, $plan->createdAt);
        } catch (CanonicalOrderPlanException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_scalar_invalid');
        }
    }

    private static function fromAcceptedComponents(
        CanonicalOrderPlanBuildRequest $request,
        \DateTimeImmutable $createdAt,
    ): self {
        $policy = $request->policy;
        $riskPolicy = $policy->riskPolicy;
        $risk = $request->risk;
        $riskRequest = $request->riskRequest;
        $costs = $request->costs;
        $zone = $request->zone;
        if (count($request->protection->targets) !== count($request->netR->targets)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_target_mismatch');
        }
        $costsByTarget = [];
        foreach ($costs->targets as $targetCost) {
            $costsByTarget[$targetCost->targetId] = $targetCost;
        }
        $targets = [];
        foreach ($request->protection->targets as $index => $protectionTarget) {
            $netTarget = $request->netR->targets[$index] ?? null;
            $targetCost = $costsByTarget[$protectionTarget->id] ?? null;
            if (
                !$netTarget instanceof CanonicalNetRTargetDecision
                || !$targetCost instanceof CanonicalTargetCostSnapshot
                || $netTarget->id !== $protectionTarget->id
                || $netTarget->price !== $protectionTarget->price
            ) {
                throw new CanonicalOrderPlanException('canonical_order_plan_target_mismatch');
            }
            $targets[] = new CanonicalOrderPlanTarget(
                id: $protectionTarget->id,
                price: $protectionTarget->price,
                riskMultiple: $protectionTarget->riskMultiple,
                liquidityRole: $protectionTarget->liquidityRole,
                spreadRate: (float) $targetCost->spreadRate,
                slippageRate: (float) $targetCost->slippageRate,
                grossReward: $netTarget->grossReward,
                entryFee: $netTarget->entryFee,
                targetFee: $netTarget->targetFee,
                entrySpreadCost: $netTarget->entrySpreadCost,
                entrySlippageCost: $netTarget->entrySlippageCost,
                targetSpreadCost: $netTarget->targetSpreadCost,
                targetSlippageCost: $netTarget->targetSlippageCost,
                fundingCost: $netTarget->fundingCost,
                netReward: $netTarget->netReward,
                netRisk: $netTarget->netRisk,
                netR: $netTarget->netR,
            );
        }
        $inputHashes = array_values(array_unique([
            ...$zone->inputHashes,
            ...$request->protection->inputHashes,
            $request->riskRequest->instrument->inputHash,
            $request->netR->costInputHash,
            ...($request->orderBook === null ? [] : [$request->orderBook->inputHash]),
        ]));
        $entryExpiresAt = $zone->expiresAt;
        $cancelAfterAt = null;
        if ($policy->orderPolicy !== null) {
            $entryTtl = $createdAt->modify('+' . $policy->orderPolicy->ttlSeconds . ' seconds');
            $cancellationDeadline = $createdAt->modify('+' . $policy->orderPolicy->cancelAfterSeconds . ' seconds');
            $entryExpiresAt = $entryTtl < $zone->expiresAt ? $entryTtl : $zone->expiresAt;
            $cancelAfterAt = $cancellationDeadline < $zone->expiresAt ? $cancellationDeadline : $zone->expiresAt;
        }
        $holdingExpiresAt = $policy->holdingHorizon === [] ? null : CanonicalHoldingBoundary::expiresAt(
            $createdAt,
            $policy->holdingWindowSeconds,
            $policy->holdingHorizon,
        );
        $values = [
            'modeId' => $riskPolicy->modeId,
            'modeVersion' => $riskPolicy->modeVersion,
            'setupId' => $riskPolicy->setupId,
            'setupVersion' => $riskPolicy->setupVersion,
            'exchange' => $riskPolicy->exchange,
            'environment' => $riskPolicy->environment,
            'symbol' => $zone->symbol,
            'marketType' => $zone->marketType,
            'quoteCurrency' => $risk->quoteCurrency,
            'side' => $riskPolicy->side,
            'orderType' => 'limit',
            'marketFallback' => $policy->orderPolicy?->marketFallback ?? false,
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
            'equityQuote' => $riskRequest->equityQuote,
            'availableBalanceQuote' => $riskRequest->availableBalanceQuote,
            'riskRate' => $riskPolicy->riskRate,
            'riskBudgetQuote' => $risk->riskBudgetQuote,
            'grossStopLoss' => $risk->grossStopLoss,
            'totalStopLoss' => $risk->totalStopLoss,
            'positionNotional' => $risk->positionNotional,
            'finalLeverage' => $risk->finalLeverage,
            'effectiveLeverageCap' => $risk->effectiveLeverageCap,
            'modeLeverageCap' => $riskPolicy->modeLeverageCap,
            'exchangeLeverageCap' => $riskRequest->exchangeLeverageCap,
            'symbolLeverageCap' => $riskRequest->symbolLeverageCap,
            'minQuantity' => $riskRequest->minQuantity,
            'maxQuantity' => $riskRequest->maxQuantity,
            'marketMaxQuantity' => $riskRequest->marketMaxQuantity,
            'exchangeMinNotional' => $riskPolicy->exchangeMinNotional,
            'exchangeMaxNotional' => $riskPolicy->exchangeMaxNotional,
            'environmentMaxNotional' => $riskPolicy->environmentMaxNotional,
            'capsApplied' => $risk->capsApplied,
            'makerFeeRate' => $riskPolicy->makerFeeRate,
            'takerFeeRate' => $riskPolicy->takerFeeRate,
            'entryLiquidityRole' => (string) $costs->entryLiquidityRole,
            'stopLiquidityRole' => (string) $costs->stopLiquidityRole,
            'entrySpreadRate' => (float) $costs->entrySpreadRate,
            'stopSpreadRate' => (float) $costs->stopSpreadRate,
            'entrySlippageRate' => (float) $costs->entrySlippageRate,
            'stopSlippageRate' => (float) $costs->stopSlippageRate,
            'fundingRate' => (float) $costs->fundingRate,
            'entryFee' => $risk->entryFee,
            'stopExitFee' => $risk->stopExitFee,
            'entrySpreadCost' => $risk->entrySpreadCost,
            'stopSpreadCost' => $risk->stopSpreadCost,
            'entrySlippageCost' => $risk->entrySlippageCost,
            'stopSlippageCost' => $risk->stopSlippageCost,
            'fundingCost' => $risk->fundingCost,
            'fundingIntervals' => $request->netR->fundingIntervals,
            'maximumInputAgeSeconds' => $policy->entryZone->maximumInputAgeSeconds,
            'inputObservedAt' => min(
                $request->protection->oldestObservedAt,
                $request->riskRequest->instrument->observedAt,
            ),
            'observedAt' => $zone->observedAt,
            'costObservedAt' => $costs->observedAt,
            'zoneComputedAt' => $zone->computedAt,
            'createdAt' => $createdAt,
            'expiresAt' => $entryExpiresAt,
            'cancelAfterAt' => $cancelAfterAt,
            'holdingExpiresAt' => $holdingExpiresAt,
            'configHash' => $policy->configHash,
            'costInputHash' => $request->netR->costInputHash,
            'orderBookInputHash' => $request->orderBook?->inputHash,
            'inputHashes' => $inputHashes,
        ];
        $planHash = self::hashValues($values);
        $values['planHash'] = $planHash;

        return CanonicalOrderPlanValidator::validateAt(new self(...$values), $createdAt);
    }

    public function expectedPlanHash(): string
    {
        $values = get_object_vars($this);
        unset($values['planHash']);

        return self::hashValues($values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $values = get_object_vars($this);
        $values['targets'] = array_map(
            static fn (CanonicalOrderPlanTarget $target): array => $target->toArray(),
            $this->targets,
        );
        foreach (['inputObservedAt', 'observedAt', 'costObservedAt', 'zoneComputedAt', 'createdAt', 'expiresAt', 'cancelAfterAt', 'holdingExpiresAt'] as $field) {
            $timestamp = $values[$field];
            if ($timestamp === null) {
                unset($values[$field]);
                continue;
            }
            if (!$timestamp instanceof \DateTimeImmutable) {
                throw new CanonicalOrderPlanException('canonical_order_plan_timestamp_invalid');
            }
            $values[$field] = $timestamp->format('Y-m-d\TH:i:s.uP');
        }
        if ($this->orderBookInputHash === null) {
            unset($values['orderBookInputHash']);
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private static function hashValues(array $values): string
    {
        if (($values['orderBookInputHash'] ?? null) === null) {
            unset($values['orderBookInputHash']);
        }
        $values['targets'] = array_map(
            static fn (CanonicalOrderPlanTarget $target): array => $target->toArray(),
            $values['targets'],
        );
        foreach (['inputObservedAt', 'observedAt', 'costObservedAt', 'zoneComputedAt', 'createdAt', 'expiresAt', 'cancelAfterAt', 'holdingExpiresAt'] as $field) {
            $timestamp = $values[$field];
            if ($timestamp === null && \in_array($field, ['cancelAfterAt', 'holdingExpiresAt'], true)) {
                continue;
            }
            if (!$timestamp instanceof \DateTimeImmutable) {
                throw new CanonicalOrderPlanException('canonical_order_plan_timestamp_invalid');
            }
            $values[$field] = $timestamp->format('Y-m-d\TH:i:s.uP');
        }

        return 'sha256:' . hash('sha256', CanonicalOrderPlanDecimal::encodeCanonicalJson(
            $values,
            'canonical_order_plan_hash_encoding_invalid',
        ));
    }

    /** @param array<string, mixed> $wire */
    private static function assertWireShape(array $wire): void
    {
        $expected = array_values(array_filter(
            self::WIRE_FIELDS,
            static fn (string $field): bool => !\in_array($field, self::OPTIONAL_WIRE_FIELDS, true)
                || array_key_exists($field, $wire),
        ));
        if (array_keys($wire) !== $expected) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_shape_invalid');
        }
        foreach (self::OPTIONAL_WIRE_FIELDS as $field) {
            if (array_key_exists($field, $wire) && $wire[$field] === null) {
                throw new CanonicalOrderPlanException('canonical_order_plan_wire_shape_invalid');
            }
        }
    }

    private static function wireFloat(mixed $value): float
    {
        if ((!\is_float($value) && !\is_int($value)) || !\is_finite((float) $value)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_scalar_invalid');
        }

        return (float) $value;
    }

    private static function wireString(mixed $value): string
    {
        if (!\is_string($value) || $value === '' || strlen($value) > 128) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_scalar_invalid');
        }

        return $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, bool $nonEmpty): array
    {
        if (!\is_array($value) || !array_is_list($value) || ($nonEmpty && $value === [])) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_scalar_invalid');
        }
        foreach ($value as $item) {
            self::wireString($item);
        }

        return $value;
    }

    /** @return non-empty-list<CanonicalOrderPlanTarget> */
    private static function targets(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_target_invalid');
        }
        $targets = [];
        foreach ($value as $target) {
            if (!\is_array($target) || array_keys($target) !== self::TARGET_FIELDS
                || !\is_string($target['id']) || $target['id'] === '' || strlen($target['id']) > 128
                || !\is_string($target['liquidityRole']) || $target['liquidityRole'] === ''
                || strlen($target['liquidityRole']) > 128
            ) {
                throw new CanonicalOrderPlanException('canonical_order_plan_wire_target_invalid');
            }
            foreach (array_diff(self::TARGET_FIELDS, ['id', 'liquidityRole']) as $field) {
                $target[$field] = self::targetFloat($target[$field]);
            }
            $targets[] = new CanonicalOrderPlanTarget(...$target);
        }

        return $targets;
    }

    private static function targetFloat(mixed $value): float
    {
        try {
            return self::wireFloat($value);
        } catch (CanonicalOrderPlanException) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_target_invalid');
        }
    }

    private static function wireTime(mixed $value): \DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_timestamp_invalid');
        }
        $time = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$time instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $time->getOffset() !== 0
            || $time->format('Y-m-d\TH:i:s.uP') !== $value
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_wire_timestamp_invalid');
        }

        return $time;
    }
}
