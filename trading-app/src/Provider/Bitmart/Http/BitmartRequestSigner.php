<?php

namespace App\Provider\Bitmart\Http;


final class BitmartRequestSigner
{
    public function __construct(
        private readonly BitmartConfig $bitmartConfig,
    ) {}

    /**
     * Construit la signature HMAC pour BitMart Futures V2.
     * Format attendu: HMAC_SHA256(secret, timestamp#memo#payload) encodé en hex.
     */
    public function sign(string $timestampMs, string $payload): string
    {
        $message = $timestampMs.'#'.$this->bitmartConfig->getApiMemo().'#'.$payload;
        return hash_hmac('sha256', $message, $this->bitmartConfig->getApiSecret());
    }

    /**
     * Construit la signature HMAC pour l'authentification WebSocket BitMart.
     * Format attendu: HMAC_SHA256(secret, timestamp#memo#bitmart.WebSocket) encodé en hex.
     *
     * @param string $timestampMs Timestamp en millisecondes (ex: "1640995200000")
     * @return string Signature hexadécimale
     */
    public function signWebSocket(string $timestampMs): string
    {
        $message = $timestampMs.'#'.$this->bitmartConfig->getApiMemo().'#bitmart.WebSocket';
        return hash_hmac('sha256', $message, $this->bitmartConfig->getApiSecret());
    }
}
