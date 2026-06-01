<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OrderIntent;
use App\Entity\SymbolExecutionLock;
use App\Provider\Context\ExchangeContext;
use App\Repository\FuturesOrderRepository;
use App\Repository\PositionRepository;
use App\Repository\SymbolExecutionLockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class SymbolExecutionLockManager
{
    private const ACTIVE_INTENT_STATUSES = [
        OrderIntent::STATUS_DRAFT,
        OrderIntent::STATUS_VALIDATED,
        OrderIntent::STATUS_READY_TO_SEND,
        OrderIntent::STATUS_SENT,
    ];

    public function __construct(
        private readonly SymbolExecutionLockRepository $locks,
        private readonly PositionRepository $positions,
        private readonly FuturesOrderRepository $orders,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly int $defaultTtlSeconds = 900,
    ) {
    }

    public function reserveForIntent(OrderIntent $intent, array $payload = []): SymbolExecutionLockReservation
    {
        $this->lockSymbolKey($intent->getExchange(), $intent->getMarketType(), $intent->getSymbol());

        $active = $this->locks->findActive($intent->getExchange(), $intent->getMarketType(), $intent->getSymbol());
        if ($active instanceof SymbolExecutionLock) {
            if ($this->canReclaimExpiredLock($active)) {
                $active->release('expired_reclaimed');
                $this->entityManager->persist($active);
                $this->entityManager->flush();
            } else {
                return SymbolExecutionLockReservation::blocked($active, $this->lockMetadata($active, $intent));
            }
        }

        if ($this->hasOpenExposureForKey($intent->getExchange(), $intent->getMarketType(), $intent->getSymbol())) {
            $lock = new SymbolExecutionLock(
                exchange: $intent->getExchange(),
                marketType: $intent->getMarketType(),
                symbol: $intent->getSymbol(),
                expiresAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(
                    sprintf('+%d seconds', $this->defaultTtlSeconds)
                ),
            );
            $lock->setPayload(array_replace($payload, ['source' => 'existing_open_exposure']));
            $this->entityManager->persist($lock);

            return SymbolExecutionLockReservation::blocked(
                $lock,
                $this->lockMetadata($lock, $intent, 'existing_open_exposure'),
                true,
            );
        }

        $lock = new SymbolExecutionLock(
            exchange: $intent->getExchange(),
            marketType: $intent->getMarketType(),
            symbol: $intent->getSymbol(),
            ownerOrderIntent: $intent,
            expiresAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(
                sprintf('+%d seconds', $this->defaultTtlSeconds)
            ),
        );
        $lock->setPayload($payload);
        $this->entityManager->persist($lock);

        return SymbolExecutionLockReservation::created($lock);
    }

    public function releaseForIntent(OrderIntent $intent, string $reason, bool $allowOpenOrder = false): bool
    {
        $active = $this->locks->findActive($intent->getExchange(), $intent->getMarketType(), $intent->getSymbol());
        if (!$active instanceof SymbolExecutionLock) {
            return false;
        }

        $owner = $active->getOwnerOrderIntent();
        if ($owner instanceof OrderIntent && $owner->getId() !== $intent->getId()) {
            return false;
        }

        if ($allowOpenOrder && $reason === 'order_intent_cancelled') {
            $this->orders->markOpenOrdersCancelledForIntent($intent);
        }

        if ($this->hasOpenPosition($active) || (!$allowOpenOrder && $this->hasOpenOrder($active))) {
            $this->logger->info('symbol_execution_lock.release_skipped_open_exposure', [
                'exchange' => $active->getExchange(),
                'market_type' => $active->getMarketType(),
                'symbol' => $active->getSymbol(),
                'lock_id' => $active->getId(),
                'reason' => $reason,
            ]);

            return false;
        }

        $active->release($reason);
        $this->entityManager->persist($active);

        return true;
    }

    public function releaseManual(
        string $symbol,
        ExchangeContext $context,
        string $reason,
        bool $force = false,
    ): bool {
        $active = $this->locks->findActive($context->exchange->value, $context->marketType->value, $symbol);
        if (!$active instanceof SymbolExecutionLock) {
            return false;
        }

        if (!$force && $this->hasOpenExposure($active)) {
            throw new \RuntimeException(sprintf(
                'Refusing to release %s while an open position or order exists. Use --force only after manual investigation.',
                $active->activeKey(),
            ));
        }

        $releaseReason = $force ? 'force:' . $reason : $reason;
        $active->release($releaseReason);
        $this->entityManager->persist($active);
        $this->entityManager->flush();

        $this->logger->warning('symbol_execution_lock.manual_release', [
            'exchange' => $active->getExchange(),
            'market_type' => $active->getMarketType(),
            'symbol' => $active->getSymbol(),
            'force' => $force,
            'reason' => $releaseReason,
            'lock_id' => $active->getId(),
        ]);

        return true;
    }

    public function releaseForSymbol(
        string $symbol,
        ExchangeContext $context,
        string $reason,
        bool $flush = false,
    ): bool {
        $active = $this->locks->findActive($context->exchange->value, $context->marketType->value, $symbol);
        if (!$active instanceof SymbolExecutionLock) {
            return false;
        }

        if ($this->hasOpenExposure($active)) {
            $this->logger->info('symbol_execution_lock.release_skipped_open_exposure', [
                'exchange' => $active->getExchange(),
                'market_type' => $active->getMarketType(),
                'symbol' => $active->getSymbol(),
                'lock_id' => $active->getId(),
                'reason' => $reason,
            ]);

            return false;
        }

        $active->release($reason);
        $this->entityManager->persist($active);

        if ($flush) {
            $this->entityManager->flush();
        }

        $this->logger->info('symbol_execution_lock.released_for_symbol', [
            'exchange' => $active->getExchange(),
            'market_type' => $active->getMarketType(),
            'symbol' => $active->getSymbol(),
            'lock_id' => $active->getId(),
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * @return SymbolExecutionLock[]
     */
    public function listActive(?ExchangeContext $context = null, ?string $symbol = null, int $limit = 100): array
    {
        return $this->locks->findActiveLocks($context, $symbol, $limit);
    }

    private function canReclaimExpiredLock(SymbolExecutionLock $lock): bool
    {
        if (!$lock->isExpired()) {
            return false;
        }

        if ($this->hasOpenExposure($lock)) {
            return false;
        }

        $owner = $lock->getOwnerOrderIntent();
        if ($owner instanceof OrderIntent && \in_array($owner->getStatus(), self::ACTIVE_INTENT_STATUSES, true)) {
            return false;
        }

        return true;
    }

    private function hasOpenExposure(SymbolExecutionLock $lock): bool
    {
        return $this->hasOpenPosition($lock) || $this->hasOpenOrder($lock);
    }

    private function hasOpenExposureForKey(string $exchange, string $marketType, string $symbol): bool
    {
        $lock = new SymbolExecutionLock($exchange, $marketType, $symbol);

        return $this->hasOpenExposure($lock);
    }

    private function hasOpenPosition(SymbolExecutionLock $lock): bool
    {
        $context = ExchangeContext::fromValues($lock->getExchange(), $lock->getMarketType());

        foreach (['LONG', 'SHORT'] as $side) {
            $position = $this->positions->findOneBySymbolSide($lock->getSymbol(), $side, $context);
            if ($position !== null && $position->getStatus() === 'OPEN') {
                return true;
            }
        }

        return false;
    }

    private function hasOpenOrder(SymbolExecutionLock $lock): bool
    {
        return $this->orders->hasOpenOrderForSymbol(
            $lock->getSymbol(),
            ExchangeContext::fromValues($lock->getExchange(), $lock->getMarketType()),
        );
    }

    /**
     * @return array{lock: array<string,mixed>}
     */
    private function lockMetadata(
        SymbolExecutionLock $lock,
        OrderIntent $currentIntent,
        ?string $blockingReason = null,
    ): array
    {
        $metadata = [
            'lock' => [
                'exchange' => $lock->getExchange(),
                'market_type' => $lock->getMarketType(),
                'symbol' => $lock->getSymbol(),
                'current_profile' => $currentIntent->getStrategyProfile(),
                'blocking_profile' => $lock->getOwnerProfile(),
                'blocking_order_intent_id' => $lock->getOwnerOrderIntentId(),
                'blocking_decision_key' => $lock->getOwnerDecisionKey(),
            ],
        ];

        if ($blockingReason !== null) {
            $metadata['lock']['blocking_reason'] = $blockingReason;
        }

        return $metadata;
    }

    private function lockSymbolKey(string $exchange, string $marketType, string $symbol): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->getDatabasePlatform()->getName() !== 'postgresql') {
            return;
        }

        $connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:scope), hashtext(:symbol_key))',
            [
                'scope' => 'symbol_execution_lock',
                'symbol_key' => sprintf('%s:%s:%s', strtolower($exchange), strtolower($marketType), strtoupper($symbol)),
            ],
        );
    }
}
