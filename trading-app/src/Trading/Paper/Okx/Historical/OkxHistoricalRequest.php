<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Historical;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use Symfony\Component\Clock\ClockInterface;

final readonly class OkxHistoricalRequest
{
    public const DEFAULT_MAXIMUM_EVENTS = 1_000_000;
    public const DEFAULT_MAXIMUM_PAGES = 100_000;

    /** @var list<string> */
    public array $symbols;

    /** @var list<string> */
    public array $bars;

    public \DateTimeImmutable $from;
    public \DateTimeImmutable $to;

    /** @param list<string> $symbols */
    public function __construct(
        public string $datasetId,
        array $symbols,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        public int $maximumEvents = self::DEFAULT_MAXIMUM_EVENTS,
        public int $maximumPages = self::DEFAULT_MAXIMUM_PAGES,
    ) {
        PaperDatasetManifest::assertDatasetId($datasetId);
        if ($maximumEvents < 1
            || $maximumEvents > self::DEFAULT_MAXIMUM_EVENTS
            || $maximumPages < 1
            || $maximumPages > self::DEFAULT_MAXIMUM_PAGES
        ) {
            throw new \InvalidArgumentException('okx_historical_bound_invalid');
        }

        $instruments = new OkxPaperInstrumentMap();
        $symbols = array_values(array_unique($symbols));
        sort($symbols, \SORT_STRING);
        if ($symbols === []) {
            throw new \InvalidArgumentException('okx_historical_symbols_invalid');
        }
        foreach ($symbols as $symbol) {
            try {
                $instruments->nativeInstrumentId($symbol);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException('okx_historical_symbols_invalid');
            }
        }

        $utc = new \DateTimeZone('UTC');
        $from = \DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $to = \DateTimeImmutable::createFromInterface($to)->setTimezone($utc);
        if ($from >= $to) {
            throw new \InvalidArgumentException('okx_historical_range_invalid');
        }

        $this->symbols = $symbols;
        $this->bars = ['1m', '5m', '15m', '1H'];
        $this->from = $from;
        $this->to = $to;
    }

    public function assertTradeRangeAvailable(ClockInterface $clock): void
    {
        $earliest = $clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify('-3 months')
            ->modify('+5 minutes');
        if ($this->from < $earliest) {
            throw new \InvalidArgumentException('okx_history_trades_range_unavailable');
        }
    }

    public function requestSha256(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_version' => 1,
            'dataset_id' => $this->datasetId,
            'venue' => 'okx',
            'symbols' => $this->symbols,
            'bars' => $this->bars,
            'from' => $this->from->format('Y-m-d\TH:i:s.u\Z'),
            'to' => $this->to->format('Y-m-d\TH:i:s.u\Z'),
            'maximum_events' => $this->maximumEvents,
            'maximum_pages' => $this->maximumPages,
        ]));
    }
}
