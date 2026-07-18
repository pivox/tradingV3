<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Enum\ExchangePositionSide;

final readonly class FakeFundingSchedule
{
    public function __construct(
        public string $symbol,
        public ExchangePositionSide $side,
        public ?string $fundingRate,
        public int $rateIntervalSeconds,
        public int $appliedIntervalSeconds,
        public string $currency,
        public \DateTimeImmutable $dueAt,
    ) {
        if (trim($this->symbol) === '' || strtoupper($this->symbol) !== $this->symbol) {
            throw new \InvalidArgumentException('fake_funding_symbol_invalid');
        }
        if ($this->rateIntervalSeconds <= 0 || $this->appliedIntervalSeconds <= 0) {
            throw new \InvalidArgumentException('fake_funding_interval_invalid');
        }
        if (trim($this->currency) === '' || strtoupper($this->currency) !== $this->currency) {
            throw new \InvalidArgumentException('fake_funding_currency_invalid');
        }
    }
}
