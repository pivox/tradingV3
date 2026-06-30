<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use App\Provider\Hyperliquid\HyperliquidSignerInterface;

final readonly class HyperliquidAgentSigner implements HyperliquidSignerInterface
{
    public function __construct(
        private HyperliquidConfig $config,
        private ?HyperliquidSignatureBackendInterface $backend = null,
        private HyperliquidSigningPayloadFactory $payloads = new HyperliquidSigningPayloadFactory(),
    ) {
    }

    public function signAction(array $action, int $nonce): array
    {
        $this->assertTestnetSignerConfigured();
        if (!$this->backend instanceof HyperliquidSignatureBackendInterface) {
            throw new \RuntimeException('hyperliquid_signature_backend_not_configured');
        }

        $payload = $this->payloads->canonicalPayload(
            $action,
            $nonce,
            $this->config->normalizedNetwork(),
            $this->config->signerAddress(),
        );

        return [
            'action' => $action,
            'nonce' => $nonce,
            'network' => $this->config->normalizedNetwork(),
            'signer' => $this->config->signerAddress(),
            'account' => $this->config->signingAccountAddress(),
            'signature' => $this->backend->sign($payload, $this->config->testnetAgentPrivateKey),
            'redacted' => true,
        ];
    }

    private function assertTestnetSignerConfigured(): void
    {
        $environment = $this->config->configuredEnvironment();
        if (!$this->config->isTestnet() || $environment === 'mainnet') {
            throw new \RuntimeException('hyperliquid_signer_mainnet_rejected');
        }

        if ($environment !== 'testnet') {
            throw new \RuntimeException('hyperliquid_signing_environment_must_be_testnet');
        }

        if ($this->config->normalizedNetwork() !== 'testnet') {
            throw new \RuntimeException('hyperliquid_signing_network_must_be_testnet');
        }

        if ($this->config->signingAccountAddress() === '') {
            throw new \RuntimeException('HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS is required for Hyperliquid testnet signing.');
        }

        if ($this->config->signerAddress() === '') {
            throw new \RuntimeException('HYPERLIQUID_TESTNET_AGENT_ADDRESS is required for Hyperliquid testnet signing.');
        }

        if (trim($this->config->testnetAgentPrivateKey) === '') {
            throw new \RuntimeException('HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY is required for Hyperliquid testnet signing.');
        }
    }
}
