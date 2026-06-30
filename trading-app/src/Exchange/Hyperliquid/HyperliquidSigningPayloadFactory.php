<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

final readonly class HyperliquidSigningPayloadFactory
{
    /**
     * @param array<string,mixed> $action
     */
    public function canonicalPayload(array $action, int $nonce, string $network, string $signer): string
    {
        $payload = [
            'action' => $this->canonicalize($action),
            'network' => strtolower(trim($network)),
            'nonce' => $nonce,
            'signer' => strtolower(trim($signer)),
        ];

        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
        if (!\is_string($json)) {
            throw new \InvalidArgumentException('Unable to encode Hyperliquid signing payload.');
        }

        return $json;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[(string) $key] = $this->canonicalize($item);
        }

        return $canonical;
    }
}
