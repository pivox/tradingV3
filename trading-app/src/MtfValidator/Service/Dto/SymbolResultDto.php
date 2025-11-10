<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto;

/**
 * DTO pour le résultat d'un symbole
 */
final class SymbolResultDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $status,
        public readonly ?string $executionTf = null,
        public readonly ?string $failedTimeframe = null,
        public readonly ?string $signalSide = null,
        public readonly ?array $tradingDecision = null,
        public readonly ?array $error = null,
        public readonly ?array $context = null,
        public readonly ?float $currentPrice = null,
        public readonly ?float $atr = null,
        public readonly ?string $validationModeUsed = null,
        public readonly ?string $tradeEntryModeUsed = null
    ) {}

    public function isSuccess(): bool
    {
        return strtoupper($this->status) === 'SUCCESS';
    }

    public function isError(): bool
    {
        return strtoupper($this->status) === 'ERROR';
    }

    public function isSkipped(): bool
    {
        return strtoupper($this->status) === 'SKIPPED';
    }

    public function hasTradingDecision(): bool
    {
        return $this->tradingDecision !== null;
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'status' => $this->status,
            'execution_tf' => $this->executionTf,
            'failed_timeframe' => $this->failedTimeframe,
            'signal_side' => $this->signalSide,
            'trading_decision' => $this->tradingDecision,
            'error' => $this->error,
            'context' => $this->context,
            'current_price' => $this->currentPrice,
            'atr' => $this->atr,
            'validation_mode_used' => $this->validationModeUsed,
            'trade_entry_mode_used' => $this->tradeEntryModeUsed
        ];
    }
}
