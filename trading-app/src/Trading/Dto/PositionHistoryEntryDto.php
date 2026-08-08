<?php

declare(strict_types=1);

namespace App\Trading\Dto;

use App\Common\Enum\PositionSide;
use App\Contract\Provider\Dto\PositionDto as ProviderPositionDto;
use Brick\Math\BigDecimal;

/**
 * DTO pour les positions fermées (historique)
 */
final class PositionHistoryEntryDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly PositionSide $side,
        public readonly BigDecimal $size,
        public readonly BigDecimal $entryPrice,
        public readonly BigDecimal $exitPrice,
        public readonly BigDecimal $realizedPnl,
        public readonly ?BigDecimal $fees,
        public readonly \DateTimeImmutable $openedAt,
        public readonly \DateTimeImmutable $closedAt,
        public readonly array $raw = [],
        public readonly ?string $exchangePositionId = null,
        public readonly ?string $exchangeOrderId = null,
        public readonly ?string $clientOrderId = null,
        public readonly ?string $exchangeFillId = null,
    ) {}

    /**
     * Mappe depuis un ProviderPositionDto fermé
     */
    public static function fromProviderDto(ProviderPositionDto $providerDto, ?BigDecimal $fees = null): self
    {
        if ($providerDto->closedAt === null) {
            throw new \InvalidArgumentException('Position must be closed to create PositionHistoryEntryDto');
        }

        // Le prix de sortie peut être dans metadata ou calculé depuis markPrice
        $exitPrice = null;
        if (isset($providerDto->metadata['exit_price'])) {
            $exitPrice = is_string($providerDto->metadata['exit_price']) 
                ? BigDecimal::of($providerDto->metadata['exit_price'])
                : BigDecimal::of((string)$providerDto->metadata['exit_price']);
        } else {
            // Utiliser markPrice comme approximation du prix de sortie
            $exitPrice = $providerDto->markPrice;
        }

        return new self(
            symbol: $providerDto->symbol,
            side: $providerDto->side,
            size: $providerDto->size,
            entryPrice: $providerDto->entryPrice,
            exitPrice: $exitPrice,
            realizedPnl: $providerDto->realizedPnl,
            fees: $fees,
            openedAt: $providerDto->openedAt,
            closedAt: $providerDto->closedAt,
            raw: $providerDto->metadata,
            exchangePositionId: $providerDto->exchangePositionId,
            exchangeOrderId: $providerDto->exchangeOrderId,
            clientOrderId: $providerDto->clientOrderId,
            exchangeFillId: $providerDto->exchangeFillId,
        );
    }

    public function canonicalEvidence(): \App\Trading\Lineage\Persistence\CanonicalPositionEvidence
    {
        return new \App\Trading\Lineage\Persistence\CanonicalPositionEvidence(
            $this->exchangePositionId,
            $this->exchangeOrderId,
            $this->clientOrderId,
            $this->exchangeFillId,
        );
    }
}
