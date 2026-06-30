<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Exchange\Hyperliquid\HyperliquidSigningPayloadFactory;

final readonly class FakeHyperliquidSigner implements HyperliquidSignerInterface
{
    private const FAKE_SIGNER_ADDRESS = '0x0000000000000000000000000000000000000fab';

    public function __construct(
        private string $seed = 'hyperliquid-fake-signer',
        private HyperliquidSigningPayloadFactory $payloads = new HyperliquidSigningPayloadFactory(),
    ) {
    }

    public function signAction(array $action, int $nonce): array
    {
        $payload = $this->payloads->canonicalPayload($action, $nonce, 'testnet', self::FAKE_SIGNER_ADDRESS);
        $r = hash('sha256', 'r:' . $this->seed . ':' . $payload);
        $s = hash('sha256', 's:' . $this->seed . ':' . $payload);

        return [
            'action' => $action,
            'nonce' => $nonce,
            'network' => 'testnet',
            'signer' => self::FAKE_SIGNER_ADDRESS,
            'signature' => [
                'scheme' => 'fake_hyperliquid_signer',
                'r' => '0x' . $r,
                's' => '0x' . $s,
                'v' => 27 + (hexdec($r[-1]) % 2),
            ],
            'redacted' => true,
        ];
    }
}
