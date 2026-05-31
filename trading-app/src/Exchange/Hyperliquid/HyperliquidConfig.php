<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

final readonly class HyperliquidConfig
{
    public function __construct(
        public string $environment = '',
        public string $accountAddress = '',
        public string $privateKey = '',
        public string $apiBaseUri = '',
        public string $wsUri = '',
        public bool $mainnetEnabled = false,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            environment: (string)($_ENV['HYPERLIQUID_ENV'] ?? $_SERVER['HYPERLIQUID_ENV'] ?? 'testnet'),
            accountAddress: (string)($_ENV['HYPERLIQUID_ACCOUNT_ADDRESS'] ?? $_SERVER['HYPERLIQUID_ACCOUNT_ADDRESS'] ?? ''),
            privateKey: (string)($_ENV['HYPERLIQUID_PRIVATE_KEY'] ?? $_SERVER['HYPERLIQUID_PRIVATE_KEY'] ?? ''),
            apiBaseUri: (string)($_ENV['HYPERLIQUID_API_BASE_URI'] ?? $_SERVER['HYPERLIQUID_API_BASE_URI'] ?? ''),
            wsUri: (string)($_ENV['HYPERLIQUID_WS_URI'] ?? $_SERVER['HYPERLIQUID_WS_URI'] ?? ''),
            mainnetEnabled: filter_var($_ENV['HYPERLIQUID_MAINNET_ENABLED'] ?? $_SERVER['HYPERLIQUID_MAINNET_ENABLED'] ?? false, \FILTER_VALIDATE_BOOL),
        );
    }

    public function normalizedEnvironment(): string
    {
        $env = strtolower(trim($this->environment));

        return $env === 'mainnet' ? 'mainnet' : 'testnet';
    }

    public function isTestnet(): bool
    {
        return $this->normalizedEnvironment() === 'testnet';
    }

    public function chainName(): string
    {
        return $this->isTestnet() ? 'Testnet' : 'Mainnet';
    }

    public function apiBaseUri(): string
    {
        $configured = rtrim(trim($this->apiBaseUri), '/');
        if ($configured !== '') {
            return $configured;
        }

        return $this->isTestnet()
            ? 'https://api.hyperliquid-testnet.xyz'
            : 'https://api.hyperliquid.xyz';
    }

    public function wsUri(): string
    {
        $configured = trim($this->wsUri);
        if ($configured !== '') {
            return $configured;
        }

        return $this->isTestnet()
            ? 'wss://api.hyperliquid-testnet.xyz/ws'
            : 'wss://api.hyperliquid.xyz/ws';
    }

    public function assertMainnetAllowed(): void
    {
        if (!$this->isTestnet() && !$this->mainnetEnabled) {
            throw new \RuntimeException('Hyperliquid mainnet is disabled by default; set HYPERLIQUID_MAINNET_ENABLED=1 explicitly.');
        }
    }

    public function assertTradingConfigured(): void
    {
        $this->assertMainnetAllowed();

        if (trim($this->accountAddress) === '') {
            throw new \RuntimeException('HYPERLIQUID_ACCOUNT_ADDRESS is required for Hyperliquid trading.');
        }
        if (trim($this->privateKey) === '') {
            throw new \RuntimeException('HYPERLIQUID_PRIVATE_KEY is required for Hyperliquid trading.');
        }
    }
}
