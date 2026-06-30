<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

final class HyperliquidAssetResolver
{
    /** @var array<string,int> */
    private array $assetIds = [];

    public function __construct(private readonly HyperliquidRestClientInterface $client)
    {
    }

    public function assetId(string $symbol): int
    {
        $normalized = $this->normalizeSymbol($symbol);
        if (isset($this->assetIds[$normalized])) {
            return $this->assetIds[$normalized];
        }

        $meta = $this->client->info(['type' => 'meta']);
        $universe = $meta['universe'] ?? [];
        if (!\is_array($universe)) {
            throw new \RuntimeException('Hyperliquid meta response missing universe');
        }

        $assetIds = [];
        foreach (array_values($universe) as $index => $asset) {
            if (!\is_array($asset) || !isset($asset['name'])) {
                continue;
            }
            $name = $this->normalizeSymbol((string) $asset['name']);
            if (isset($assetIds[$name])) {
                throw new \RuntimeException(sprintf('hyperliquid_asset_collision:%s', $name));
            }

            $assetIds[$name] = (int) $index;
        }

        $this->assetIds = array_replace($this->assetIds, $assetIds);

        if (!isset($this->assetIds[$normalized])) {
            throw new \InvalidArgumentException(sprintf('Unknown Hyperliquid asset "%s"', $symbol));
        }

        return $this->assetIds[$normalized];
    }

    public function coin(string $symbol): string
    {
        return $this->normalizeSymbol($symbol);
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        foreach (['-PERP', 'PERP', '/USDC', '-USDC', 'USDC', '/USDT', '-USDT', 'USDT'] as $suffix) {
            if (str_ends_with($symbol, $suffix)) {
                $symbol = substr($symbol, 0, -strlen($suffix));
                break;
            }
        }

        return $symbol;
    }
}
