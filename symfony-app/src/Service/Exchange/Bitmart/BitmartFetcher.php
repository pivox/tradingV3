<?php

declare(strict_types=1);

namespace App\Service\Exchange\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Dto\ContractDetailsDto;
use App\Dto\FuturesKlineDto;
use App\Service\Exchange\Bitmart\Dto\ContractDto;
use App\Service\Exchange\Bitmart\Dto\KlineDto;
use App\Service\Exchange\ExchangeFetcherInterface;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

final class BitmartFetcher implements ExchangeFetcherInterface
{
    private const KLINE_MAX_LIMIT = 500;

    public function __construct(
        private readonly BitmartHttpClientPublic $publicClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return ContractDto[]
     */
    public function fetchContracts(): array
    {
        $collection = $this->publicClient->getContractDetails();
        $contracts = [];
        foreach ($collection->all() as $details) {
            if (!$details instanceof ContractDetailsDto) {
                continue;
            }
            // Reproduire le filtrage précédent (status Trading et non delisté)
            if ($details->status !== 'Trading') {
                continue;
            }
            if ($details->delistTimeSec > 0 && $details->delistTimeSec <= time()) {
                $this->logger->notice('Skipping delisted contract', [
                    'symbol' => $details->symbol,
                    'delist_time' => $details->delistTimeSec,
                ]);
                continue;
            }

            $contracts[] = ContractDto::fromApi($this->detailsToArray($details));
        }

        return $contracts;
    }

    /**
     * @return KlineDto[]
     */
    public function fetchKlines(
        string $symbol,
        DateTimeInterface|int $startTime,
        DateTimeInterface|int $endTime,
        int $step = 1,
        array $listDates = []
    ): array {
        $start = $this->toDateTimeImmutable($startTime);
        $end   = $this->toDateTimeImmutable($endTime);

        if ($end <= $start) {
            return [];
        }

        $results = [];
        $cursor  = $start;
        $stepMinutes = $step;
        $chunkDurationMinutes = $stepMinutes * self::KLINE_MAX_LIMIT;

        while ($cursor < $end) {
            $chunkEnd = $cursor->add(new DateInterval('PT' . $chunkDurationMinutes . 'M'));
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $collection = $this->publicClient->getFuturesKlines(
                symbol:   $symbol,
                step:     $stepMinutes,
                fromTs:   $cursor->getTimestamp(),
                toTs:     $chunkEnd->getTimestamp(),
                limit:    self::KLINE_MAX_LIMIT
            );

            foreach ($collection as $kline) {
                if (!$kline instanceof FuturesKlineDto) {
                    continue;
                }
                $results[] = new KlineDto(
                    timestamp: $kline->timestamp,
                    open:      $kline->open,
                    high:      $kline->high,
                    low:       $kline->low,
                    close:     $kline->close,
                    volume:    $kline->volume,
                );
            }

            $cursor = $chunkEnd;
            usleep(200_000); // respect des limites API
        }

        return $results;
    }

    /**
     * @return KlineDto[]
     */
    public function fetchLatestKlines(string $symbol, int $limit = 100, int $step = 1): array
    {
        $end = new DateTimeImmutable();
        $durationMinutes = $limit * $step;
        $start = $end->sub(new DateInterval('PT' . $durationMinutes . 'M'));

        return $this->fetchKlines($symbol, $start, $end, $step);
    }

    private function toDateTimeImmutable(DateTimeInterface|int $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        $timestamp = (string)$value;
        if (strlen($timestamp) > 10) {
            $timestamp = substr($timestamp, 0, 10);
        }

        return (new DateTimeImmutable())->setTimestamp((int)$timestamp);
    }

    private function detailsToArray(ContractDetailsDto $details): array
    {
        return [
            'symbol'                => $details->symbol,
            'product_type'          => $details->productType,
            'open_timestamp'        => $details->openTimestampMs,
            'expire_timestamp'      => $details->expireTimestampMs,
            'settle_timestamp'      => $details->settleTimestampMs,
            'base_currency'         => $details->baseCurrency,
            'quote_currency'        => $details->quoteCurrency,
            'last_price'            => $details->lastPrice,
            'volume_24h'            => $details->volume24h,
            'turnover_24h'          => $details->turnover24h,
            'index_price'           => $details->indexPrice,
            'index_name'            => $details->indexName,
            'contract_size'         => $details->contractSize,
            'min_leverage'          => $details->minLeverage,
            'max_leverage'          => $details->maxLeverage,
            'price_precision'       => $details->pricePrecision,
            'vol_precision'         => $details->volPrecision,
            'max_volume'            => $details->maxVolume,
            'market_max_volume'     => $details->marketMaxVolume,
            'min_volume'            => $details->minVolume,
            'funding_rate'          => $details->fundingRate,
            'expected_funding_rate' => $details->expectedFundingRate,
            'open_interest'         => $details->openInterest,
            'open_interest_value'   => $details->openInterestValue,
            'high_24h'              => $details->high24h,
            'low_24h'               => $details->low24h,
            'change_24h'            => $details->change24h,
            'funding_interval_hours'=> $details->fundingIntervalHours,
            'status'                => $details->status,
            'delist_time'           => $details->delistTimeSec,
        ];
    }
}
