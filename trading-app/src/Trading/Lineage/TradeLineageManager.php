<?php

declare(strict_types=1);

namespace App\Trading\Lineage;

use App\Entity\OrderIntent;
use App\Entity\TradeLineage;
use App\Provider\Context\ExchangeContext;
use App\Repository\TradeLineageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class TradeLineageManager
{
    public function __construct(
        private readonly TradeLineageRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $context
     */
    public function ensureForIntent(OrderIntent $intent, array $context = []): TradeLineage
    {
        $intentId = $intent->getId();
        if ($intentId !== null) {
            $existing = $this->repository->findOneByOrderIntentId($intentId);
            if ($existing instanceof TradeLineage) {
                return $existing;
            }
        }

        $existingByClient = $this->repository->findOneByClientOrderId(
            $intent->getClientOrderId(),
            ExchangeContext::fromValues($intent->getExchange(), $intent->getMarketType()),
        );
        if ($existingByClient instanceof TradeLineage) {
            if ($existingByClient->getOrderIntent() === null) {
                $existingByClient->setOrderIntent($intent);
                $this->entityManager->flush();
            }

            return $existingByClient;
        }

        $internalTradeId = $this->contextString($context, 'internal_trade_id')
            ?? $this->contextString($context, 'trade_id')
            ?? $this->generateInternalTradeId();

        $lineage = (new TradeLineage($internalTradeId, $intent->getClientOrderId(), $intent->getSymbol()))
            ->setOrderIntent($intent)
            ->setExchange($intent->getExchange())
            ->setMarketType($intent->getMarketType())
            ->setSide($this->sideFromIntent($intent))
            ->setProfile($this->contextString($context, 'profile') ?? $intent->getStrategyProfile())
            ->setRunId($this->contextString($context, 'run_id'))
            ->setCorrelationRunId($this->contextString($context, 'correlation_run_id'))
            ->setOrchestrationRunId($this->contextString($context, 'orchestration_run_id'))
            ->setOrchestrationSetId($this->contextString($context, 'orchestration_set_id'))
            ->setOrchestrationDashboardId($this->contextString($context, 'orchestration_dashboard_id'))
            ->setOrigin($this->contextString($context, 'origin') ?? 'runtime');

        $this->entityManager->persist($lineage);
        $this->entityManager->flush();

        $this->logger->debug('[TradeLineage] Created lineage mapping', [
            'internal_trade_id' => $lineage->getInternalTradeId(),
            'order_intent_id' => $intent->getId(),
            'client_order_id' => $lineage->getClientOrderId(),
            'exchange' => $lineage->getExchange(),
            'market_type' => $lineage->getMarketType(),
            'symbol' => $lineage->getSymbol(),
        ]);

        return $lineage;
    }

    public function attachExchangeOrderId(TradeLineage $lineage, ?string $exchangeOrderId): void
    {
        if ($exchangeOrderId === null || trim($exchangeOrderId) === '') {
            return;
        }

        if ($lineage->getExchangeOrderId() === $exchangeOrderId) {
            return;
        }

        $lineage->setExchangeOrderId($exchangeOrderId);
        $this->entityManager->flush();
    }

    public function attachPositionId(TradeLineage $lineage, ?string $positionId): void
    {
        if ($positionId === null || trim($positionId) === '') {
            return;
        }

        if ($lineage->getPositionId() === $positionId) {
            return;
        }

        $lineage->setPositionId($positionId);
        $this->entityManager->flush();
    }

    public function resolve(
        ?ExchangeContext $context,
        ?string $internalTradeId = null,
        ?string $clientOrderId = null,
        ?string $exchangeOrderId = null,
        ?string $positionId = null,
        ?string $symbol = null,
        ?string $side = null,
    ): ?TradeLineage {
        unset($symbol, $side);

        $internalTradeId = $this->normalize($internalTradeId);
        if ($internalTradeId !== null) {
            return $this->repository->findOneByInternalTradeId($internalTradeId);
        }

        $clientOrderId = $this->normalize($clientOrderId);
        if ($clientOrderId !== null) {
            return $this->repository->findOneByClientOrderId($clientOrderId, $context);
        }

        $exchangeOrderId = $this->normalize($exchangeOrderId);
        if ($exchangeOrderId !== null) {
            return $this->repository->findOneByExchangeOrderId($exchangeOrderId, $context);
        }

        $positionId = $this->normalize($positionId);
        if ($positionId !== null) {
            return $this->repository->findOneByPositionId($positionId, $context);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function lifecycleExtra(TradeLineage $lineage): array
    {
        return array_filter([
            'internal_trade_id' => $lineage->getInternalTradeId(),
            'trade_id' => $lineage->getInternalTradeId(),
            'run_id' => $lineage->getRunId(),
            'correlation_run_id' => $lineage->getCorrelationRunId(),
            'orchestration_run_id' => $lineage->getOrchestrationRunId(),
            'orchestration_set_id' => $lineage->getOrchestrationSetId(),
            'orchestration_dashboard_id' => $lineage->getOrchestrationDashboardId(),
            'profile' => $lineage->getProfile(),
            'origin' => $lineage->getOrigin(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string,mixed> $context
     */
    private function contextString(array $context, string $key): ?string
    {
        return $this->normalize($context[$key] ?? null);
    }

    private function normalize(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function generateInternalTradeId(): string
    {
        try {
            return 'itd:' . bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return 'itd:' . str_replace('.', '', uniqid('', true));
        }
    }

    private function sideFromIntent(OrderIntent $intent): ?string
    {
        return match ($intent->getSide()) {
            1, 3 => 'LONG',
            2, 4 => 'SHORT',
            default => null,
        };
    }
}
