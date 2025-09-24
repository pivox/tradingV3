<?php
// src/Dto/ExchangeFilters.php

declare(strict_types=1);

namespace App\Dto;

final class ExchangeFilters
{
    public function __construct(
        public readonly float $tickSize,         // pas de prix (ex: 0.01)
        public readonly float $stepSize,         // pas de quantité (ex: 0.001)
        public readonly ?float $minPrice = null, // prix min (optionnel)
        public readonly ?float $maxPrice = null, // prix max (optionnel)
        public readonly ?float $minQty   = null, // quantité min
        public readonly ?float $maxQty   = null, // quantité max
        public readonly ?float $minNotional = null, // notional min (prix*qty)
        public readonly ?int   $priceScale = null,   // nombre de décimales autorisées
        public readonly ?int   $qtyScale   = null,   // nombre de décimales sur la quantité
    ) {}
}
