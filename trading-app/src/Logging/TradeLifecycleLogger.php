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
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::ORDER_SUBMITTED)
            ->setRunId($runId)
            ->setExchange($exchange)
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
        ?string $reasonCode = null,
        ?string $runId = null,
        ?string $exchange = null,
        ?string $accountId = null,
        array $extra = [],
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::ORDER_EXPIRED)
            ->setRunId($runId)
            ->setExchange($exchange)
            ->setAccountId($accountId)
            ->setOrderId($orderId)
            ->setClientOrderId($clientOrderId)
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
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::POSITION_OPENED)
            ->setRunId($runId)
            ->setExchange($exchange)
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
    ): void {
        $event = $this->newEvent($symbol, TradeLifecycleEventType::SYMBOL_SKIPPED)
            ->setRunId($runId)
            ->setReasonCode($reasonCode)
            ->setTimeframe($timeframe)
            ->setConfigProfile($configProfile)
            ->setConfigVersion($configVersion)
            ->setExtra($this->normalizeExtra($extra));

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
}
