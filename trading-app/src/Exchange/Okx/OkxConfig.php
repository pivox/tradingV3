<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

final readonly class OkxConfig
{
    private const string DEMO_API_BASE_URI = 'https://eea.okx.com';
    private const string LIVE_API_BASE_URI = 'https://www.okx.com';

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

        return $this->isDemo() ? self::DEMO_API_BASE_URI : self::LIVE_API_BASE_URI;
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
        $this->assertPrivateRestEndpointAllowed();
        $this->assertLiveAllowed();

        if ($this->isDemo()) {
            if (!$this->simulatedTrading) {
                throw new \RuntimeException('OKX_SIMULATED_TRADING=1 is required for OKX demo private requests.');
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

    private function assertPrivateRestEndpointAllowed(): void
    {
        $expected = $this->isDemo() ? self::DEMO_API_BASE_URI : self::LIVE_API_BASE_URI;
        $configured = $this->apiBaseUri === '' ? $expected : $this->apiBaseUri;

        if ($configured !== $expected) {
            throw new \RuntimeException('okx_private_rest_endpoint_not_allowed');
        }
    }
}
