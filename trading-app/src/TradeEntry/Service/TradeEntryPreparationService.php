<?php

declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\Config\TradeEntryConfigResolver;
use App\Logging\Dto\LifecycleContextBuilder;
use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Exception\EntryZoneOutOfBoundsException;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\Policy\DailyLossGuard;
use App\TradeEntry\Workflow\BuildOrderPlan;
use App\TradeEntry\Workflow\BuildPreOrder;
use Psr\Clock\ClockInterface;
use App\Trading\Lineage\CanonicalTradeEntryConfigFactory;
use App\TradeEntry\Policy\CanonicalTradeRuntimePolicyValidator;

final readonly class TradeEntryPreparationService
{
    public function __construct(
        private BuildPreOrder $preflight,
        private BuildOrderPlan $planner,
        private TradeEntryConfigResolver $configResolver,
        private ?ExecutionBox $executionBox = null,
        private ?DailyLossGuard $dailyLossGuard = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function prepare(
        TradeEntryRequest $request,
        string $decisionKey,
        string $mode,
        LifecycleContextBuilder $lifecycle,
        ?string $internalTradeId = null,
        ?string $paperCellId = null,
        ?string $sourceEventId = null,
    ): PreparedTradeEntry {
        if ($request->lineageContext?->isModern()) {
            $identity = $request->canonicalIdentity();
            if ($decisionKey !== $identity->decisionKey) {
                throw new \App\Trading\Lineage\LineageContextException('canonical_identity_mismatch:decisionKey');
            }
            if ($mode !== $identity->modeId) {
                throw new \App\Trading\Lineage\LineageContextException('canonical_identity_mismatch:mode_id');
            }
        }
        $tradeId = $this->tradeId($request->symbol, $decisionKey, $internalTradeId, $paperCellId, $sourceEventId);
        $lifecycle->withDecisionKey($decisionKey)->withProfile($mode)->withInternalTradeId($tradeId)->withTradeId($tradeId);

        $modern = $request->lineageContext?->isModern() === true;
        $config = $modern
            ? CanonicalTradeEntryConfigFactory::fromLineage($request->lineageContext)
            : $this->configResolver->resolve($mode);
        if ($modern) {
            CanonicalTradeRuntimePolicyValidator::assertReady($config);
        }

        if ($this->dailyLossGuard instanceof DailyLossGuard) {
            if ($modern) {
                $state = $this->dailyLossGuard->checkAndMaybeLock($mode, $config);
            } else {
                try {
                    $state = $this->dailyLossGuard->checkAndMaybeLock($mode);
                } catch (\Throwable) {
                    $state = null;
                }
            }
            if ($state !== null && $state['locked'] === true) {
                return $this->terminal($request, $decisionKey, $tradeId, $lifecycle, $mode, 'daily_loss_limit_reached', $state);
            }
        }
        $preflight = ($this->preflight)($request, $decisionKey);
        try {
            $plan = ($this->planner)($request, $preflight, $decisionKey);
        } catch (EntryZoneOutOfBoundsException $exception) {
            return $this->terminal($request, $decisionKey, $tradeId, $lifecycle, $mode, $exception->getReason(), [
                'message' => $exception->getMessage(),
                'context' => $exception->getContext(),
            ], $preflight);
        }

        try {
            $fallback = $config->getFallbackEndOfZoneConfig();
            if ($fallback->enabled && $this->executionBox instanceof ExecutionBox) {
                $zone = new EntryZone($plan->entryZoneLow ?? PHP_FLOAT_MIN, $plan->entryZoneHigh ?? PHP_FLOAT_MAX, 'plan_zone');
                $currentPrice = (float) ($preflight->markPrice ?? ($request->side->value === 'long' ? $preflight->bestAsk : $preflight->bestBid));
                $ttl = $this->remainingZoneTtl($plan->zoneExpiresAt);
                $decision = $this->executionBox->applyEndOfZoneFallback($fallback, $zone, $request->symbol, $currentPrice, $ttl, $request->exchangeContext);
                CanonicalTradeRuntimePolicyValidator::assertNoEndOfZoneFallbackRewrite($modern, $decision);
                if (is_array($decision)) {
                    $orderType = $decision['order_type'];
                    $plan = $plan->copyWith(orderType: $orderType, orderMode: $orderType === 'market' ? 1 : $plan->orderMode);
                }
            }
        } catch (\Throwable $exception) {
            if ($modern) {
                throw $exception;
            }
            // Preserve the legacy non-blocking fallback policy.
        }

        if ($plan->leverage <= 0) {
            return $this->terminal($request, $decisionKey, $tradeId, $lifecycle, $mode, 'leverage_below_threshold', [
                'leverage' => $plan->leverage,
                'min_allowed_leverage' => 0,
                'notional_usdt' => $plan->entry * $plan->contractSize * $plan->size,
                'size' => $plan->size,
                'contract_size' => $plan->contractSize,
                'entry' => $plan->entry,
            ], $preflight);
        }

        return new PreparedTradeEntry($plan, null, $decisionKey, $tradeId, $lifecycle, $mode, $request->executionTf ?? '', $preflight);
    }

    /** @param array<string, mixed> $raw */
    private function terminal(
        TradeEntryRequest $request,
        string $decisionKey,
        string $tradeId,
        LifecycleContextBuilder $lifecycle,
        string $mode,
        string $reason,
        array $raw,
        ?\App\TradeEntry\Dto\PreflightReport $preflight = null,
    ): PreparedTradeEntry {
        return new PreparedTradeEntry(
            null,
            new ExecutionResult('PREP-SKIP-' . substr(hash('sha256', $decisionKey . '|' . $reason), 0, 12), null, ExecutionResult::STATUS_SKIPPED, ['reason' => $reason] + $raw),
            $decisionKey,
            $tradeId,
            $lifecycle,
            $mode,
            $request->executionTf ?? '',
            $preflight,
        );
    }

    private function tradeId(string $symbol, string $decisionKey, ?string $provided, ?string $cellId, ?string $sourceEventId): string
    {
        if ($cellId !== null || $sourceEventId !== null) {
            if ($cellId === null || $sourceEventId === null) {
                throw new \InvalidArgumentException('paper_trade_identity_incomplete');
            }

            return 'ptrd:' . hash('sha256', $cellId . '|' . $sourceEventId . '|' . $decisionKey);
        }
        if ($provided !== null && $provided !== '') {
            return $provided;
        }

        try {
            return 'trd:' . strtolower($symbol) . ':' . bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return uniqid('trd:' . strtolower($symbol) . ':', true);
        }
    }

    private function remainingZoneTtl(?\DateTimeImmutable $expiresAt): int
    {
        if ($expiresAt === null) {
            return PHP_INT_MAX;
        }

        return max(0, $expiresAt->getTimestamp() - ($this->clock?->now()->getTimestamp() ?? time()));
    }
}
