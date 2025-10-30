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
        public readonly string $currency,
        public readonly BigDecimal $availableBalance,
        public readonly BigDecimal $frozenBalance,
        public readonly BigDecimal $unrealized,
        public readonly BigDecimal $equity,
        public readonly BigDecimal $positionDeposit,
        public readonly array $metadata = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            currency: (string)($data['currency'] ?? ''),
            availableBalance: BigDecimal::of((string)($data['available_balance'] ?? '0')),
            frozenBalance: BigDecimal::of((string)($data['frozen_balance'] ?? '0')),
            unrealized: BigDecimal::of((string)($data['unrealized'] ?? '0')),
            equity: BigDecimal::of((string)($data['equity'] ?? '0')),
            positionDeposit: BigDecimal::of((string)($data['position_deposit'] ?? '0')),
            metadata: $data['metadata'] ?? []
        );
    }
}
