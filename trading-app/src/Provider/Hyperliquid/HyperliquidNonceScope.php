<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

final readonly class HyperliquidNonceScope
{
    public string $environment;
    public string $network;
    public string $accountAddress;
    public string $signerAddress;

    public function __construct(
        string $environment,
        string $network,
        string $accountAddress,
        string $signerAddress,
    ) {
        $this->environment = $this->normalizeRequired($environment, 'environment');
        $this->network = $this->normalizeRequired($network, 'network');
        $this->accountAddress = $this->normalizeRequired($accountAddress, 'account_address');
        $this->signerAddress = $this->normalizeRequired($signerAddress, 'signer_address');
    }

    private function normalizeRequired(string $value, string $name): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            throw new \InvalidArgumentException(sprintf('hyperliquid_nonce_scope_missing_%s', $name));
        }

        return $normalized;
    }
}
