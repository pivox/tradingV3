<?php
declare(strict_types=1);


namespace App\Service\Trading;


use App\Dto\ExchangeFilters;

interface TradingPort
{
    public function getEquity(): float;
    public function getAvailableUSDT(): float;
    public function getFilters(string $symbol): ExchangeFilters;
    public function passesLiquidationGuard(string $symbol, string $side, float $entry, float $stop, float $minRatio): bool;
    public function placeOrderPlan(array $payload): array;
}
