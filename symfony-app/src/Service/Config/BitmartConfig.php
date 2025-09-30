<?php
// src/Config/BitmartConfig.php
namespace App\Service\Config;

class BitmartConfig
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $apiMemo
    ) {}

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    public function getApiMemo(): string
    {
        return $this->apiMemo;
    }
}
