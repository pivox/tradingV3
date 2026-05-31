<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

final readonly class ExchangeCapabilities
{
    public function __construct(
        public bool $supportsTestnet = false,
        public bool $supportsWebSocketPrivate = false,
        public bool $supportsClientOrderId = true,
        public bool $supportsCancelByClientOrderId = false,
        public bool $supportsPostOnly = false,
        public bool $supportsIoc = false,
        public bool $supportsReduceOnly = false,
        public bool $supportsAttachedStopLossOnEntry = false,
        public bool $supportsAttachedTakeProfitOnEntry = false,
        public bool $supportsTriggerOrders = false,
        public bool $supportsModifyOrder = false,
        public bool $requiresSeparateLeverageSubmit = false,
        public bool $supportsPerSymbolLeverage = false,
    ) {
    }

    /**
     * @return array<string,bool>
     */
    public function toArray(): array
    {
        return [
            'supportsTestnet' => $this->supportsTestnet,
            'supportsWebSocketPrivate' => $this->supportsWebSocketPrivate,
            'supportsClientOrderId' => $this->supportsClientOrderId,
            'supportsCancelByClientOrderId' => $this->supportsCancelByClientOrderId,
            'supportsPostOnly' => $this->supportsPostOnly,
            'supportsIoc' => $this->supportsIoc,
            'supportsReduceOnly' => $this->supportsReduceOnly,
            'supportsAttachedStopLossOnEntry' => $this->supportsAttachedStopLossOnEntry,
            'supportsAttachedTakeProfitOnEntry' => $this->supportsAttachedTakeProfitOnEntry,
            'supportsTriggerOrders' => $this->supportsTriggerOrders,
            'supportsModifyOrder' => $this->supportsModifyOrder,
            'requiresSeparateLeverageSubmit' => $this->requiresSeparateLeverageSubmit,
            'supportsPerSymbolLeverage' => $this->supportsPerSymbolLeverage,
        ];
    }
}
