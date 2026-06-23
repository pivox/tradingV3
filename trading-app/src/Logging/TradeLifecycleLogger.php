<?php

declare(strict_types=1);

namespace App\Logging;

use App\Entity\TradeLifecycleEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class TradeLifecycleLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function logOrderSubmitted(
        string $symbol,
        string $orderId,
        ?string $clientOrderId,
        string $side,
        string $qty,
        ?string $price,
        ?string $runId = null,
        ?string $exchange = null,
        ?string $accountId = null,
        array $extra = [],
        ?string $timeframe = null,
        ?string $configProfile = null,
        ?string $configVersion = null,
        ?string $planId = null,
        ?string $marketType = null,
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::ORDER_SUBMITTED)
            ->setRunId($runId)
            ->setExchange($exchange)
            ->setMarketType($marketType)
            ->setAccountId($accountId)
            ->setOrderId($orderId)
            ->setClientOrderId($clientOrderId)
            ->setSide($side)
            ->setQty($qty)
            ->setPrice($price)
            ->setTimeframe($timeframe)
            ->setConfigProfile($configProfile)
            ->setConfigVersion($configVersion)
            ->setPlanId($planId)
            ->setExtra($this->normalizeExtra($extra));

        $this->persist($event);
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function logOrderExpired(
        string $symbol,
        string $orderId,
        ?string $clientOrderId,
        ?string $side = null,
        ?string $reasonCode = null,
        ?string $runId = null,
        ?string $exchange = null,
        ?string $accountId = null,
        array $extra = [],
        ?string $marketType = null,
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::ORDER_EXPIRED)
            ->setRunId($runId)
            ->setExchange($exchange)
            ->setMarketType($marketType)
            ->setAccountId($accountId)
            ->setOrderId($orderId)
            ->setClientOrderId($clientOrderId)
            ->setSide($side)
            ->setReasonCode($reasonCode)
            ->setExtra($this->normalizeExtra($extra));

        $this->persist($event);
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function logPositionOpened(
        string $symbol,
        ?string $positionId,
        string $side,
        string $qty,
        ?string $entryPrice,
        ?string $runId = null,
        ?string $exchange = null,
        ?string $accountId = null,
        array $extra = [],
        ?string $marketType = null,
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::POSITION_OPENED)
            ->setRunId($runId)
            ->setExchange($exchange)
            ->setMarketType($marketType)
            ->setAccountId($accountId)
            ->setPositionId($positionId)
            ->setSide($side)
            ->setQty($qty)
            ->setPrice($entryPrice)
            ->setExtra($this->normalizeExtra($extra));

        $this->persist($event);
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function logSymbolSkipped(
        string $symbol,
        string $reasonCode,
        ?string $runId = null,
        ?string $timeframe = null,
        ?string $configProfile = null,
        ?string $configVersion = null,
        array $extra = [],
        ?string $exchange = null,
        ?string $marketType = null,
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::SYMBOL_SKIPPED)
            ->setRunId($runId)
            ->setExchange($exchange)
            ->setMarketType($marketType)
            ->setReasonCode($reasonCode)
            ->setTimeframe($timeframe)
            ->setConfigProfile($configProfile)
            ->setConfigVersion($configVersion)
            ->setExtra($this->normalizeExtra($extra));

        $this->persist($event);
    }

    /**
     * @param array<string,mixed>|null $extra
     */
    public function logPositionClosed(
        string $symbol,
        string $positionId,
        ?string $side,
        ?string $runId = null,
        ?string $exchange = null,
        ?string $accountId = null,
        ?string $reasonCode = null,
        ?array $extra = null,
        ?string $marketType = null,
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::POSITION_CLOSED)
            ->setPositionId($positionId)
            ->setSide($side)
            ->setRunId($runId)
            ->setExchange($exchange)
            ->setMarketType($marketType)
            ->setAccountId($accountId)
            ->setReasonCode($reasonCode);

        if ($extra !== null) {
            $event->setExtra($this->normalizeExtra($extra));
        }

        $this->persist($event);
    }


    private function newEvent(string $symbol, string $eventType): TradeLifecycleEvent
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        return (new TradeLifecycleEvent($symbol, $eventType, $now))
            ->setHappenedAt($now);
    }

    private function persist(TradeLifecycleEvent $event): void
    {
        $extra = $event->getExtra();
        if (\is_array($extra)) {
            $internalTradeId = $extra['internal_trade_id'] ?? null;
            if (\is_scalar($internalTradeId) && trim((string) $internalTradeId) !== '') {
                $event->setInternalTradeId((string) $internalTradeId);
            }
            $this->copyLineageExtraToColumns($event, $extra);
        }

        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>|null
     */
    private function normalizeExtra(array $extra): ?array
    {
        return $extra === [] ? null : $extra;
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function copyLineageExtraToColumns(TradeLifecycleEvent $event, array $extra): void
    {
        $event
            ->setInternalPositionId($this->stringValue($extra['internal_position_id'] ?? null))
            ->setCorrelationRunId($this->stringValue($extra['correlation_run_id'] ?? null))
            ->setOrchestrationRunId($this->stringValue($extra['orchestration_run_id'] ?? null))
            ->setOrchestrationSetId($this->stringValue($extra['orchestration_set_id'] ?? null))
            ->setOrchestrationDashboardId($this->stringValue($extra['orchestration_dashboard_id'] ?? null))
            ->setOrigin($this->stringValue($extra['origin'] ?? null) ?? 'legacy')
            ->setReplayOfRunId($this->stringValue($extra['replay_of_run_id'] ?? null))
            ->setReplayOfCorrelationId($this->stringValue($extra['replay_of_correlation_id'] ?? null))
            ->setAttemptNumber($this->intValue($extra['attempt_number'] ?? null))
            ->setConfigHash($this->stringValue($extra['config_hash'] ?? null));
    }

    private function stringValue(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function intValue(mixed $value): int
    {
        if (\is_int($value)) {
            return max(1, $value);
        }
        if (\is_string($value) && ctype_digit($value)) {
            return max(1, (int) $value);
        }

        return 1;
    }
}
