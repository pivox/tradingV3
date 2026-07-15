<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangeOrderType;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class FakeInstrument
{
    /**
     * @param list<ExchangeOrderType> $allowedOrderTypes
     */
    public function __construct(
        public string $symbol,
        public MarketType $marketType,
        public string $baseAsset,
        public string $quoteAsset,
        public string $settleAsset,
        public string $priceTick,
        public string $quantityStep,
        public string $minQuantity,
        public string $minNotional,
        public string $contractSize,
        public int $maxLeverage,
        public string $maintenanceMarginRate,
        public array $allowedOrderTypes,
    ) {
        self::assertCanonicalUppercase($this->symbol, 'symbol');
        self::assertCanonicalUppercase($this->baseAsset, 'baseAsset');
        self::assertCanonicalUppercase($this->quoteAsset, 'quoteAsset');
        self::assertCanonicalUppercase($this->settleAsset, 'settleAsset');

        foreach ([
            'priceTick' => $this->priceTick,
            'quantityStep' => $this->quantityStep,
            'minQuantity' => $this->minQuantity,
            'minNotional' => $this->minNotional,
            'contractSize' => $this->contractSize,
            'maintenanceMarginRate' => $this->maintenanceMarginRate,
        ] as $field => $value) {
            self::assertPositiveDecimal($value, $field);
        }

        if ($this->maxLeverage <= 0) {
            throw new \InvalidArgumentException('maxLeverage must be greater than zero');
        }

        if ($this->allowedOrderTypes === []) {
            throw new \InvalidArgumentException('allowedOrderTypes cannot be empty');
        }

        $seenOrderTypes = [];
        foreach ($this->allowedOrderTypes as $orderType) {
            if (!$orderType instanceof ExchangeOrderType) {
                throw new \InvalidArgumentException('allowedOrderTypes must contain only ExchangeOrderType values');
            }
            if (isset($seenOrderTypes[$orderType->value])) {
                throw new \InvalidArgumentException('allowedOrderTypes must contain unique values');
            }
            $seenOrderTypes[$orderType->value] = true;
        }
    }

    public function isPriceQuantized(string $price): bool
    {
        return BigDecimal::of($price)->remainder($this->priceTick)->isZero();
    }

    public function isQuantityQuantized(string $quantity): bool
    {
        return BigDecimal::of($quantity)->remainder($this->quantityStep)->isZero();
    }

    private static function assertCanonicalUppercase(string $value, string $field): void
    {
        if ($value === '' || trim($value) !== $value || strtoupper($value) !== $value) {
            throw new \InvalidArgumentException(sprintf('%s must be non-blank canonical uppercase', $field));
        }
    }

    private static function assertPositiveDecimal(string $value, string $field): void
    {
        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException $exception) {
            throw new \InvalidArgumentException(sprintf('%s must be a parseable decimal', $field), 0, $exception);
        }

        if (!$decimal->isGreaterThan(0)) {
            throw new \InvalidArgumentException(sprintf('%s must be greater than zero', $field));
        }
    }
}
