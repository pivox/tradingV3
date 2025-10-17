<?php

namespace App\Service\Config;

class BitmartWsConfig extends BitmartConfig
{
    public function __construct(
        string $apiKey,
        string $secretKey,
        string $apiMemo,
        private readonly string $wsBaseUrl,
        private readonly string $device
    ) {
        parent::__construct($apiKey, $secretKey, $apiMemo);
    }

    public function getWsBaseUrl(): string
    {
        return $this->wsBaseUrl;
    }

    /**
     * Exporte la configuration sous forme de tableau (utile pour debug/logs).
     */
    public function toArray(): array
    {
        return [
            'api_key'     => $this->getApiKey(),
            'api_memo'    => $this->getApiMemo(),
            'ws_base_url' => $this->wsBaseUrl,
            'device'      => $this->device,
        ];
    }

    public function getDevice(): string
    {
        return $this->device;
    }

}
