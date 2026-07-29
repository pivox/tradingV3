<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\Dataset\HyperliquidHistoricalCoverage;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;

final readonly class HyperliquidHistoricalRequest
{
    public string $datasetId;

    /** @var list<string> */
    public array $symbols;

    /** @var list<string> */
    public array $intervals;

    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    /** @param list<string> $symbols */
    public function __construct(
        string $datasetId,
        public PaperMarketDataNetwork $network,
        array $symbols,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        public int $maximumEvents = 1_000_000,
        public int $maximumPages = 100_000,
        public int $maximumResponseBytes = 1_048_576,
        public int $maximumRetries = 5,
    ) {
        PaperDatasetManifest::assertDatasetId($datasetId);
        if ($this->network === PaperMarketDataNetwork::LEGACY_UNKNOWN) {
            throw new \InvalidArgumentException('hyperliquid_historical_network_invalid');
        }
        $this->datasetId = self::networkScopedDatasetId($datasetId, $this->network);
        if ($this->maximumEvents < 1 || $this->maximumEvents > 1_000_000
            || $this->maximumPages < 1 || $this->maximumPages > 100_000
            || $this->maximumResponseBytes < 1 || $this->maximumResponseBytes > 1_048_576
            || $this->maximumRetries < 0 || $this->maximumRetries > 5
        ) {
            throw new \InvalidArgumentException('hyperliquid_historical_bound_invalid');
        }

        $symbols = self::normalizeSymbols($symbols);
        $utc = new \DateTimeZone('UTC');
        $from = \DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $to = \DateTimeImmutable::createFromInterface($to)->setTimezone($utc);
        if ($from >= $to) {
            throw new \InvalidArgumentException('hyperliquid_historical_range_invalid');
        }

        $this->symbols = $symbols;
        $this->intervals = ['1m', '5m', '15m', '1h'];
        $this->from = $from;
        $this->to = $to;
    }

    private static function networkScopedDatasetId(
        string $datasetId,
        PaperMarketDataNetwork $network,
    ): string {
        $suffix = '--' . $network->value;
        if (str_ends_with($datasetId, $suffix)) {
            return $datasetId;
        }
        $maximumBaseBytes = 128 - strlen($suffix);
        if (strlen($datasetId) > $maximumBaseBytes) {
            $digest = substr(hash('sha256', $datasetId), 0, 16);
            $datasetId = substr(
                $datasetId,
                0,
                $maximumBaseBytes - strlen($digest) - 1,
            ) . '-' . $digest;
        }
        $scoped = $datasetId . $suffix;
        PaperDatasetManifest::assertDatasetId($scoped);

        return $scoped;
    }

    public function requestSha256(): string
    {
        return HyperliquidHistoricalRequestIdentity::sha256(
            $this->datasetId,
            $this->network,
            $this->symbols,
            $this->intervals,
            $this->from->format('Y-m-d\TH:i:s.u\Z'),
            $this->to->format('Y-m-d\TH:i:s.u\Z'),
            $this->maximumEvents,
            $this->maximumPages,
            $this->maximumResponseBytes,
            $this->maximumRetries,
        );
    }

    public function historicalCoverage(): HyperliquidHistoricalCoverage
    {
        return new HyperliquidHistoricalCoverage(
            schemaVersion: 1,
            requestSha256: $this->requestSha256(),
            from: $this->from->format('Y-m-d\TH:i:s.u\Z'),
            to: $this->to->format('Y-m-d\TH:i:s.u\Z'),
            intervals: $this->intervals,
            maximumEvents: $this->maximumEvents,
            maximumPages: $this->maximumPages,
            maximumResponseBytes: $this->maximumResponseBytes,
            maximumRetries: $this->maximumRetries,
        );
    }

    /** @param array<mixed> $symbols
     *  @return list<string>
     */
    private static function normalizeSymbols(array $symbols): array
    {
        if ($symbols === []) {
            throw new \InvalidArgumentException('hyperliquid_historical_symbols_invalid');
        }

        $instruments = new HyperliquidPaperInstrumentMap();
        foreach ($symbols as $symbol) {
            if (!\is_string($symbol)) {
                throw new \InvalidArgumentException('hyperliquid_historical_symbols_invalid');
            }

            try {
                $instruments->nativeCoin($symbol);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException('hyperliquid_historical_symbols_invalid');
            }
        }

        $symbols = array_values(array_unique($symbols));
        sort($symbols, \SORT_STRING);

        return $symbols;
    }
}
