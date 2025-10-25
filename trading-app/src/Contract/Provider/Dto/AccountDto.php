<?php

declare(strict_types=1);

namespace App\Contract\Provider\Dto;

use Brick\Math\BigDecimal;

/**
 * DTO pour les informations de compte
 */
final class AccountDto extends BaseDto
{
    public function __construct(
        public readonly string $accountId,
        public readonly BigDecimal $totalBalance,
        public readonly BigDecimal $availableBalance,
        public readonly BigDecimal $usedBalance,
        public readonly BigDecimal $unrealizedPnl,
        public readonly BigDecimal $realizedPnl,
        public readonly string $currency,
        public readonly \DateTimeImmutable $lastUpdated,
        public readonly array $metadata = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            accountId: $data['account_id'],
            totalBalance: BigDecimal::of($data['total_balance']),
            availableBalance: BigDecimal::of($data['available_balance']),
            usedBalance: BigDecimal::of($data['used_balance']),
            unrealizedPnl: BigDecimal::of($data['unrealized_pnl'] ?? 0),
            realizedPnl: BigDecimal::of($data['realized_pnl'] ?? 0),
            currency: $data['currency'],
            lastUpdated: new \DateTimeImmutable($data['last_updated']),
            metadata: $data['metadata'] ?? []
        );
    }
}


