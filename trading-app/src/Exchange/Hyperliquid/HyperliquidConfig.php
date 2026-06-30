<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

final readonly class HyperliquidConfig
{
    public function __construct(
        public string $environment = '',
        public string $apiBaseUri = '',
        public string $wsUri = '',
        public bool $mainnetEnabled = false,
        public string $network = '',
        public string $testnetAgentPrivateKey = '',
        public string $testnetAgentAddress = '',
        public string $testnetAccountAddress = '',
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            environment: (string)($_ENV['HYPERLIQUID_ENV'] ?? $_SERVER['HYPERLIQUID_ENV'] ?? 'testnet'),
            apiBaseUri: (string)($_ENV['HYPERLIQUID_API_BASE_URI'] ?? $_SERVER['HYPERLIQUID_API_BASE_URI'] ?? ''),
            wsUri: (string)($_ENV['HYPERLIQUID_WS_URI'] ?? $_SERVER['HYPERLIQUID_WS_URI'] ?? ''),
            mainnetEnabled: filter_var($_ENV['HYPERLIQUID_MAINNET_ENABLED'] ?? $_SERVER['HYPERLIQUID_MAINNET_ENABLED'] ?? false, \FILTER_VALIDATE_BOOL),
            network: (string)($_ENV['HYPERLIQUID_NETWORK'] ?? $_SERVER['HYPERLIQUID_NETWORK'] ?? $_ENV['HYPERLIQUID_ENV'] ?? $_SERVER['HYPERLIQUID_ENV'] ?? 'testnet'),
            testnetAgentPrivateKey: (string)($_ENV['HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY'] ?? $_SERVER['HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY'] ?? ''),
            testnetAgentAddress: (string)($_ENV['HYPERLIQUID_TESTNET_AGENT_ADDRESS'] ?? $_SERVER['HYPERLIQUID_TESTNET_AGENT_ADDRESS'] ?? ''),
            testnetAccountAddress: (string)($_ENV['HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS'] ?? $_SERVER['HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS'] ?? ''),
        );
    }

    public function normalizedEnvironment(): string
    {
        $env = strtolower(trim($this->environment));

        return $env === 'mainnet' ? 'mainnet' : 'testnet';
    }

    public function configuredEnvironment(): string
    {
        return strtolower(trim($this->environment));
    }

    public function isTestnet(): bool
    {
        return $this->normalizedEnvironment() === 'testnet';
    }

    public function normalizedNetwork(): string
    {
        $network = strtolower(trim($this->network !== '' ? $this->network : $this->environment));

        return $network === '' ? 'testnet' : $network;
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

        $environment = $this->configuredEnvironment();
        if (!$this->isTestnet() || $environment === 'mainnet') {
            throw new \RuntimeException('hyperliquid_trading_mainnet_rejected');
        }

        if ($environment !== 'testnet') {
            throw new \RuntimeException('hyperliquid_trading_environment_must_be_testnet');
        }

        if ($this->normalizedNetwork() !== 'testnet') {
            throw new \RuntimeException('hyperliquid_trading_network_must_be_testnet');
        }

        if ($this->signingAccountAddress() === '') {
            throw new \RuntimeException('HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS is required for Hyperliquid testnet signing.');
        }
        if ($this->signerAddress() === '') {
            throw new \RuntimeException('HYPERLIQUID_TESTNET_AGENT_ADDRESS is required for Hyperliquid testnet signing.');
        }
        if (trim($this->testnetAgentPrivateKey) === '') {
            throw new \RuntimeException('HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY is required for Hyperliquid testnet signing.');
        }
    }

    public function signingAccountAddress(): string
    {
        return strtolower(trim($this->testnetAccountAddress));
    }

    public function signerAddress(): string
    {
        return strtolower(trim($this->testnetAgentAddress));
    }
}
