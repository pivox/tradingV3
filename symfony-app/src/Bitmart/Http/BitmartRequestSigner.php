<?php

declare(strict_types=1);

namespace App\Bitmart\Http;

final class BitmartRequestSigner
{
    public function __construct(
        private readonly string $apiSecret,
        private readonly string $apiMemo,
    ) {}

    /**
     * Construit la signature HMAC pour BitMart Futures V2.
     * Format attendu: HMAC_SHA256(secret, timestamp#memo#payload) encodé en hex.
     */
    public function sign(string $timestampMs, string $payload): string
    {
        $message = $timestampMs.'#'.$this->apiMemo.'#'.$payload;
        return hash_hmac('sha256', $message, $this->apiSecret);
    }

    public function getMemo(): string
    {
        return $this->apiMemo;
    }
}


