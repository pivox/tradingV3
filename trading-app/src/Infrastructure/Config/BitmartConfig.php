<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final class BitmartConfig
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $publicApiUrl,
        private readonly string $privateApiUrl,
        private readonly int $timeout = 30,
        private readonly int $maxRetries = 3,
        private readonly string $apiKey = '',
        private readonly string $apiSecret = '',
        private readonly string $apiMemo = ''
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            baseUrl: $_ENV['BITMART_BASE_URL'] ?? 'https://api-cloud-v2.bitmart.com',
            publicApiUrl: $_ENV['BITMART_PUBLIC_API_URL'] ?? 'https://api-cloud-v2.bitmart.com',
            privateApiUrl: $_ENV['BITMART_PRIVATE_API_URL'] ?? 'https://api-cloud-v2.bitmart.com',
            timeout: (int) ($_ENV['BITMART_TIMEOUT'] ?? 30),
            maxRetries: (int) ($_ENV['BITMART_MAX_RETRIES'] ?? 3),
            apiKey: $_ENV['BITMART_API_KEY'] ?? '',
            apiSecret: $_ENV['BITMART_SECRET_KEY'] ?? '',
            apiMemo: $_ENV['BITMART_API_MEMO'] ?? ''
        );
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getPublicApiUrl(): string
    {
        return $this->publicApiUrl;
    }

    public function getPrivateApiUrl(): string
    {
        return $this->privateApiUrl;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    // URLs spécifiques
    public function getKlinesUrl(): string
    {
        return $this->publicApiUrl . '/contract/public/kline';
    }

    public function getContractsUrl(): string
    {
        return $this->publicApiUrl . '/contract/public/details';
    }

    public function getTickerUrl(): string
    {
        return $this->publicApiUrl . '/contract/public/ticker';
    }

    public function getOrderUrl(): string
    {
        return $this->privateApiUrl . '/contract/private/submit-order';
    }

    public function getCancelOrderUrl(): string
    {
        return $this->privateApiUrl . '/contract/private/cancel-order';
    }

    public function getPositionsUrl(): string
    {
        return $this->privateApiUrl . '/contract/private/position-v2';
    }

    public function getAssetsUrl(): string
    {
        return $this->privateApiUrl . '/contract/private/assets-detail';
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    public function getApiMemo(): string
    {
        return $this->apiMemo;
    }

    public function getSetLeverageUrl(): string
    {
        return $this->privateApiUrl . '/contract/private/submit-leverage';
    }

    public function getTpSlUrl(): string
    {
        return $this->privateApiUrl . '/contract/private/submit-tp-sl-order';
    }
}

