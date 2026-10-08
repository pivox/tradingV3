<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Contract\Provider\MainProviderInterface;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Dto\{EntryZone, FallbackEndOfZoneConfig};
use App\TradeEntry\Helper\SpreadHelper;
use App\TradeEntry\Policy\OrderModePolicyInterface;
use App\Config\TradeEntryConfigResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

final class ExecutionBox
{
    public function __construct(
        private readonly MainProviderInterface $providers,
        private readonly OrderModePolicyInterface $orderModePolicy,
        private readonly TradeEntryConfigResolver $tradeEntryConfigResolver,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function preparePlan(
        OrderPlanModel $plan,
        ?string $mode = null,
        ?string $executionTf = null,
        ?string $decisionKey = null,
    ): OrderPlanModel {
        $this->orderModePolicy->enforce($plan);

        return $this->applyTimeframeMultiplier($plan, $mode, $executionTf, $decisionKey);
    }

    private function applyTimeframeMultiplier(
        OrderPlanModel $plan,
        ?string $mode,
        ?string $executionTf,
        ?string $decisionKey
    ): OrderPlanModel {
        $tfKey = '5m';
        if (is_string($executionTf)) {
            $executionTf = trim($executionTf);
            if ($executionTf !== '') {
                $tfKey = strtolower($executionTf);
            }
        }

        try {
            $config = $this->tradeEntryConfigResolver->resolve($mode);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('execution.timeframe_multiplier.resolve_failed', [
                'mode' => $mode,
                'execution_tf' => $tfKey,
                'error' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);
            return $plan;
        }

        $defaults = $config->getDefaults();
        $leverageCfg = $config->getLeverage();
        $multipliers = $leverageCfg['timeframe_multipliers'] ?? [];
        if (!\is_array($multipliers)) {
            $multipliers = [];
        }
        $tfMultiplier = (float)($multipliers[$tfKey] ?? 1.0);
        if (!\is_finite($tfMultiplier) || $tfMultiplier <= 0.0) {
            $tfMultiplier = 1.0;
        }

        $effectiveMultiplier = $tfMultiplier;
        $maxLossPct = $leverageCfg['max_loss_pct'] ?? null;
        if ($maxLossPct !== null) {
            $maxLossPct = (float)$maxLossPct;
            if (\is_finite($maxLossPct)) {
                if ($maxLossPct > 1.0) {
                    $maxLossPct *= 0.01;
                }
                if ($maxLossPct <= 0.0) {
                    $maxLossPct = null;
                }
            } else {
                $maxLossPct = null;
            }
        }
        $maxLossUsdt = null;
        $maxSizeAllowed = null;
        if ($maxLossPct !== null) {
            $capital = (float)($defaults['initial_margin_usdt'] ?? 0.0);
            $riskPerContract = abs($plan->entry - $plan->stop) * $plan->contractSize;
            if ($capital > 0.0 && $riskPerContract > 0.0) {
                $maxLossUsdt = $capital * $maxLossPct;
                $maxSizeAllowed = (int)floor($maxLossUsdt / $riskPerContract);
                if ($maxSizeAllowed > 0) {
                    $maxMultiplier = $maxSizeAllowed / max(1.0, (float)$plan->size);
                    if (\is_finite($maxMultiplier) && $maxMultiplier > 0.0) {
                        $effectiveMultiplier = min($effectiveMultiplier, $maxMultiplier);
                    } else {
                        $effectiveMultiplier = 0.0;
                    }
                } else {
                    $effectiveMultiplier = 0.0;
                }
            }
        }

        $scaledSize = (int)floor($plan->size * $effectiveMultiplier);
        if ($scaledSize < 0) {
            $scaledSize = 0;
        }

        $scaledLeverageRaw = $plan->leverage * $effectiveMultiplier;
        $roundMode = strtolower((string)($leverageCfg['rounding']['mode'] ?? 'ceil'));
        $scaledLeverage = match ($roundMode) {
            'floor' => (int)floor($scaledLeverageRaw),
            'round' => (int)round($scaledLeverageRaw),
            default => (int)ceil($scaledLeverageRaw),
        };

        if ($scaledLeverage < 0) {
            $scaledLeverage = 0;
        }
        if ($scaledLeverage === 0 && $scaledSize > 0) {
            $scaledLeverage = 1;
        }
        if ($scaledLeverage < 1 && $scaledSize > 0) {
            $scaledLeverage = 1;
        }

        $floorCfg = isset($leverageCfg['floor']) ? (float)$leverageCfg['floor'] : null;
        if ($floorCfg !== null && \is_finite($floorCfg) && $floorCfg > 0.0) {
            $scaledLeverage = max($scaledLeverage, (int)ceil($floorCfg));
        }

        if ($tfMultiplier === 1.0) {
            $exchangeCapCfg = isset($leverageCfg['exchange_cap']) ? (float)$leverageCfg['exchange_cap'] : null;
            if ($exchangeCapCfg !== null && \is_finite($exchangeCapCfg) && $exchangeCapCfg > 0.0) {
                $scaledLeverage = min($scaledLeverage, (int)floor($exchangeCapCfg));
            }
        }

        $multiplierCapped = abs($effectiveMultiplier - $tfMultiplier) > 1e-9;
        if ($scaledSize === $plan->size && $scaledLeverage === $plan->leverage && !$multiplierCapped) {
            return $plan;
        }

        $this->positionsLogger->debug('execution.timeframe_multiplier_applied', [
            'symbol' => $plan->symbol,
            'execution_tf' => $tfKey,
            'tf_multiplier' => $tfMultiplier,
            'effective_multiplier' => $effectiveMultiplier,
            'max_loss_pct' => $maxLossPct,
            'max_loss_usdt' => $maxLossUsdt,
            'risk_per_contract' => abs($plan->entry - $plan->stop) * $plan->contractSize,
            'max_size_allowed' => $maxSizeAllowed,
            'base_size' => $plan->size,
            'scaled_size' => $scaledSize,
            'base_leverage' => $plan->leverage,
            'scaled_leverage' => $scaledLeverage,
            'mode' => $mode,
            'decision_key' => $decisionKey,
        ]);

        return $plan->copyWith(size: $scaledSize, leverage: $scaledLeverage);
    }

    /**
     * Fallback "fin de zone": si la zone expire sous peu et que les garde-fous sont OK,
     * décider d'un passage en taker (market ou limit selon config).
     * Retourne une petite décision structurelle pour que l'appelant adapte le plan.
     *
     * @return array{mode:string,order_type:string,reason:string}|null
     */
    public function applyEndOfZoneFallback(
        FallbackEndOfZoneConfig $cfg,
        EntryZone $zone,
        string $symbol,
        float $currentPrice,
        int $ttlRemainingSec,
        ?ExchangeContext $context = null,
    ): ?array {
        if (!$cfg->enabled || $ttlRemainingSec > $cfg->ttlThresholdSec) {
            return null;
        }

        $orderBook = $this->providersFor($context)->getOrderProvider()->getOrderBookTop($symbol);
        $spreadBps = SpreadHelper::calculateSpreadBps($orderBook);

        if ($spreadBps > $cfg->maxSpreadBps) {
            $this->positionsLogger->info("Skip fallback: spread too high ({$spreadBps} bps)");
            return null;
        }

        if ($cfg->onlyIfWithinZone && !$zone->contains($currentPrice)) {
            $this->positionsLogger->info('Skip fallback: price outside zone');
            return null;
        }

        // Approximated anchor: mid of the zone when not explicitly provided
        $anchor = max(1e-12, 0.5 * ($zone->min + $zone->max));
        $slippageBps = abs(($currentPrice - $anchor) / $anchor) * 10_000.0;
        if ($slippageBps > $cfg->maxSlippageBps) {
            $this->positionsLogger->info("Skip fallback: slippage too large ({$slippageBps} bps)");
            return null;
        }

        $this->positionsLogger->notice("Applying end-of-zone fallback taker for {$symbol}");

        return [
            'mode' => 'taker',
            'order_type' => $cfg->takerOrderType,
            'reason' => 'end_of_zone_fallback',
        ];
    }

    private function providersFor(?ExchangeContext $context = null): MainProviderInterface
    {
        return $this->providers->forContext($context);
    }
}
