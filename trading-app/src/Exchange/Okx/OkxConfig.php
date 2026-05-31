<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

final readonly class OkxConfig
{
    public function __construct(
        public string $environment = '',
        public string $apiKey = '',
        public string $apiSecret = '',
        public string $apiPassphrase = '',
        public string $apiBaseUri = '',
        public string $wsPublicUri = '',
        public string $wsPrivateUri = '',
        public bool $demoTradingEnabled = false,
        public bool $liveEnabled = false,
    ) {
    }

    public function normalizedEnvironment(): string
    {
        $env = strtolower(trim($this->environment));

        return $env === 'live' ? 'live' : 'demo';
    }

    public function isDemo(): bool
    {
        return $this->normalizedEnvironment() === 'demo';
    }

    public function apiBaseUri(): string
    {
        $configured = rtrim(trim($this->apiBaseUri), '/');
        if ($configured !== '') {
            return $configured;
        }

        return 'https://www.okx.com';
    }

    public function wsPublicUri(): string
    {
        $configured = trim($this->wsPublicUri);
        if ($configured !== '') {
            return $configured;
        }

        return 'wss://ws.okx.com:8443/ws/v5/public';
    }

    public function wsPrivateUri(): string
    {
        $configured = trim($this->wsPrivateUri);
        if ($configured !== '') {
            return $configured;
        }

        return $this->isDemo()
            ? 'wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999'
            : 'wss://ws.okx.com:8443/ws/v5/private';
    }

    public function assertLiveAllowed(): void
    {
        if (!$this->isDemo() && !$this->liveEnabled) {
            throw new \RuntimeException('OKX live trading is disabled by default; set OKX_LIVE_ENABLED=1 explicitly.');
        }
    }

    public function assertPrivateConfigured(): void
    {
        $this->assertLiveAllowed();

        if (trim($this->apiKey) === '') {
            throw new \RuntimeException('OKX_API_KEY is required for OKX private requests.');
        }
        if (trim($this->apiSecret) === '') {
            throw new \RuntimeException('OKX_API_SECRET is required for OKX private requests.');
        }
        if (trim($this->apiPassphrase) === '') {
            throw new \RuntimeException('OKX_API_PASSPHRASE is required for OKX private requests.');
        }
    }

    public function assertTradingConfigured(): void
    {
        $this->assertPrivateConfigured();

        if ($this->isDemo() && !$this->demoTradingEnabled) {
            throw new \RuntimeException('OKX demo trading is disabled by default; set OKX_DEMO_TRADING_ENABLED=1 explicitly.');
        }
    }
}
