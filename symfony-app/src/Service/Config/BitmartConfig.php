<?php
// src/Config/BitmartConfig.php
namespace App\Service\Config;

class BitmartConfig
{
    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $secretKey,
        protected readonly string $apiMemo
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
