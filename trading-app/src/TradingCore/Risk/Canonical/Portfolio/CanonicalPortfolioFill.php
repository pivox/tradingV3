<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

final readonly class CanonicalPortfolioFill
{
    public function __construct(
        public CanonicalPortfolioScope $scope,
        public string $decisionKey,
        public string $planHash,
        public string $admissionHash,
        public string $fillId,
        public float $quantity,
        public float $price,
        public float $entryFeeQuote,
        public float $protectedQuantityAfter,
        public float $remainingOrderQuantity,
        public \DateTimeImmutable $observedAt,
        public string $inputHash,
    ) {
        if (
            preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $decisionKey) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $planHash) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $admissionHash) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $fillId) !== 1
            || !self::positive($quantity)
            || !self::positive($price)
            || !self::nonNegative($entryFeeQuote)
            || !self::nonNegative($protectedQuantityAfter)
            || !self::nonNegative($remainingOrderQuantity)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $inputHash) !== 1
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_fill_invalid');
        }
    }

    public function eventHash(): string
    {
        return 'sha256:' . hash('sha256', CanonicalPortfolioDecimal::encode([
            'scope' => $this->scope->toArray(),
            'decision_key' => $this->decisionKey,
            'plan_hash' => $this->planHash,
            'admission_hash' => $this->admissionHash,
            'fill_id' => $this->fillId,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'entry_fee_quote' => $this->entryFeeQuote,
            'protected_quantity_after' => $this->protectedQuantityAfter,
            'remaining_order_quantity' => $this->remainingOrderQuantity,
            'observed_at' => $this->observedAt->format('Y-m-d\TH:i:s.uP'),
            'input_hash' => $this->inputHash,
        ], 'canonical_portfolio_fill_hash_invalid'));
    }

    private static function positive(float $value): bool
    {
        return \is_finite($value) && $value > 0.0;
    }

    private static function nonNegative(float $value): bool
    {
        return \is_finite($value) && $value >= 0.0;
    }
}
