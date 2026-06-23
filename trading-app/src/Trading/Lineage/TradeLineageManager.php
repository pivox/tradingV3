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

        $internalTradeId = $this->contextString($context, 'internal_trade_id', 96)
            ?? $this->contextString($context, 'trade_id', 96)
            ?? $this->generateInternalTradeId();

        $lineage = (new TradeLineage($internalTradeId, $intent->getClientOrderId(), $intent->getSymbol()))
            ->setOrderIntent($intent)
            ->setExchange($intent->getExchange())
            ->setMarketType($intent->getMarketType())
            ->setSide($this->sideFromIntent($intent))
            ->setProfile($this->contextString($context, 'profile', 80) ?? $this->limitString($intent->getStrategyProfile(), 80))
            ->setRunId($this->contextString($context, 'run_id', 96))
            ->setCorrelationRunId($this->contextString($context, 'correlation_run_id', 96))
            ->setOrchestrationRunId($this->contextString($context, 'orchestration_run_id', 255))
            ->setOrchestrationSetId($this->contextString($context, 'orchestration_set_id', 96))
            ->setOrchestrationDashboardId($this->contextString($context, 'orchestration_dashboard_id', 96))
            ->setOrigin($this->contextString($context, 'origin', 24) ?? 'legacy')
            ->setInternalPositionId($this->contextString($context, 'internal_position_id', 96))
            ->setReplayOfRunId($this->contextString($context, 'replay_of_run_id', 255))
            ->setReplayOfCorrelationId($this->contextString($context, 'replay_of_correlation_id', 96))
            ->setAttemptNumber($this->contextInt($context, 'attempt_number'))
            ->setConfigHash($this->contextString($context, 'config_hash', 128));

        $this->syncIntentLineage($intent, $lineage);

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

        $lineage->setExchangeOrderId($this->limitString($exchangeOrderId, 96));
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

        $lineage->setPositionId($this->limitString($positionId, 96));
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
            'internal_position_id' => $lineage->getInternalPositionId(),
            'replay_of_run_id' => $lineage->getReplayOfRunId(),
            'replay_of_correlation_id' => $lineage->getReplayOfCorrelationId(),
            'attempt_number' => $lineage->getAttemptNumber(),
            'config_hash' => $lineage->getConfigHash(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string,mixed> $context
     */
    private function contextString(array $context, string $key, ?int $maxLength = null): ?string
    {
        return $this->limitString($this->normalize($context[$key] ?? null), $maxLength);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function contextInt(array $context, string $key): int
    {
        $value = $context[$key] ?? null;
        if (\is_int($value)) {
            return max(1, $value);
        }
        if (\is_string($value) && ctype_digit($value)) {
            return max(1, (int) $value);
        }

        return 1;
    }

    private function syncIntentLineage(OrderIntent $intent, TradeLineage $lineage): void
    {
        if (!method_exists($intent, 'setInternalTradeId')) {
            return;
        }

        $intent
            ->setInternalTradeId($lineage->getInternalTradeId())
            ->setInternalPositionId($lineage->getInternalPositionId())
            ->setCorrelationRunId($lineage->getCorrelationRunId())
            ->setOrchestrationRunId($lineage->getOrchestrationRunId())
            ->setOrchestrationSetId($lineage->getOrchestrationSetId())
            ->setOrchestrationDashboardId($lineage->getOrchestrationDashboardId())
            ->setOrigin($lineage->getOrigin())
            ->setReplayOfRunId($lineage->getReplayOfRunId())
            ->setReplayOfCorrelationId($lineage->getReplayOfCorrelationId())
            ->setAttemptNumber($lineage->getAttemptNumber())
            ->setConfigHash($lineage->getConfigHash());
    }

    private function normalize(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function limitString(?string $value, ?int $maxLength): ?string
    {
        if ($value === null || $maxLength === null || strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
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
