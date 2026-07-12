<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Provider\Hyperliquid\Dto\HyperliquidMarginSafetyEvidence;
use Psr\Clock\ClockInterface;

final readonly class HyperliquidMarginSafetyEvidenceProvider implements HyperliquidMarginSafetyEvidenceProviderInterface
{
    private const MAX_TOTAL_CALL_DURATION_MILLISECONDS = 2_000;

    public function __construct(
        private HyperliquidRestClientInterface $client,
        private HyperliquidConfig $config,
        private HyperliquidMarginSafetyEvidenceMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function current(string $symbol): HyperliquidMarginSafetyEvidence
    {
        $account = $this->config->signingAccountAddress();
        if (preg_match('/^0x[a-f0-9]{40}$/D', $account) !== 1) {
            throw new \RuntimeException('hyperliquid_margin_account_unavailable');
        }

        $startedAt = $this->clock->now();
        $meta = $this->client->info(['type' => 'meta']);
        $coin = $this->coin($meta, $symbol);
        $activeAssetData = $this->client->info([
            'type' => 'activeAssetData',
            'user' => $account,
            'coin' => $coin,
        ]);
        $duration = $this->milliseconds($this->clock->now()) - $this->milliseconds($startedAt);
        if ($duration < 0 || $duration > self::MAX_TOTAL_CALL_DURATION_MILLISECONDS) {
            throw new \RuntimeException('hyperliquid_margin_evidence_read_timed_out');
        }

        return $this->mapper->map(
            meta: $meta,
            activeAssetData: $activeAssetData,
            symbol: $symbol,
            accountAddress: $account,
            observedAt: $startedAt,
        );
    }

    /** @param array<mixed> $meta */
    private function coin(array $meta, string $symbol): string
    {
        $universe = $meta['universe'] ?? null;
        if (!is_array($universe)) {
            throw new \RuntimeException('hyperliquid_margin_universe_unavailable');
        }
        $matches = [];
        foreach ($universe as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $coin = strtoupper((string) ($asset['name'] ?? ''));
            if ($coin !== '' && $coin . 'USDT' === strtoupper($symbol)) {
                $matches[] = $coin;
            }
        }
        if (count($matches) !== 1) {
            throw new \RuntimeException('hyperliquid_margin_asset_unavailable');
        }

        return $matches[0];
    }

    private function milliseconds(\DateTimeInterface $time): int
    {
        return ((int) $time->format('U') * 1_000) + (int) $time->format('v');
    }
}
