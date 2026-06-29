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
        public bool $simulatedTrading = false,
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

        return $this->isDemo() ? 'https://eea.okx.com' : 'https://www.okx.com';
    }

    public function wsPublicUri(): string
    {
        $configured = trim($this->wsPublicUri);
        if ($configured !== '') {
            return $configured;
        }

        return $this->isDemo()
            ? 'wss://wseeapap.okx.com:8443/ws/v5/public'
            : 'wss://ws.okx.com:8443/ws/v5/public';
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

        if ($this->isDemo()) {
            if (!$this->simulatedTrading) {
                throw new \RuntimeException('OKX_SIMULATED_TRADING=1 is required for OKX demo private requests.');
            }
            if (str_contains(strtolower($this->apiBaseUri()), 'www.okx.com')) {
                throw new \RuntimeException('OKX demo private requests must not use production OKX REST URL.');
            }
            if ($this->liveEnabled) {
                throw new \RuntimeException('OKX_LIVE_ENABLED must remain disabled when OKX_ENV=demo.');
            }
        }

        if (trim($this->apiKey) === '') {
            throw new \RuntimeException('OKX_DEMO_API_KEY is required for OKX demo private requests.');
        }
        if (trim($this->apiSecret) === '') {
            throw new \RuntimeException('OKX_DEMO_API_SECRET is required for OKX demo private requests.');
        }
        if (trim($this->apiPassphrase) === '') {
            throw new \RuntimeException('OKX_DEMO_API_PASSPHRASE is required for OKX demo private requests.');
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
