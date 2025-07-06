<?php

namespace App\Service\Exchange\Bitmart;

use App\Service\Exchange\Bitmart\Dto\ContractDto;
use App\Service\Exchange\Bitmart\Dto\KlineDto;
use App\Service\Exchange\ExchangeFetcherInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BitmartFetcher implements ExchangeFetcherInterface
{
    const BASE_URL="https://api-cloud-v2.bitmart.com";

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ClockInterface $clock
    ) {}

    public function fetchContracts(): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL .'/contract/public/details');
        $data = $response->toArray(false);

        $contracts = [];
        $now = $this->clock->now()->getTimestamp();
        foreach ($data['data']['symbols'] ?? [] as $entry) {
            // Skip if not active
            if ($entry['status'] !== 'Trading') {
                continue;
            }

            // Skip if delisted (delist_time > 0 and already passed)
            if ((int)$entry['delist_time'] > 0 && (int)$entry['delist_time'] <= $now) {
                $this->logger->critical("*************************");
                $this->logger->critical("*************************");
                $this->logger->critical("********* HUGE OPPORTUNITY **********");
                $this->logger->critical(json_encode($entry));
                $this->logger->critical("*************************");
                $this->logger->critical("*************************");
                continue;
            }

            $contracts[] = ContractDto::fromApi($entry);
        }

        return $contracts;
    }

    public function fetchKlines(string $symbol, \DateTimeInterface $startTime, \DateTimeInterface $endTime, int $step = 1): array
    {
        $maxCount = 500;
        $stepMinutes = $step;
        $chunkDuration = $stepMinutes * $maxCount; // durée maximale d’un appel en minutes
        $results = [];

        $cursor = \DateTimeImmutable::createFromInterface($startTime);

        while ($cursor < $endTime) {
            $chunkEnd = $cursor->add(new \DateInterval("PT{$chunkDuration}M"));
            if ($chunkEnd > $endTime) {
                $chunkEnd = \DateTimeImmutable::createFromInterface($endTime);
            }

            $parameters = [
                'symbol'     => $symbol,
                'step'       => $step,
                'start_time' => $cursor->getTimestamp(),
                'end_time'   => $chunkEnd->getTimestamp(),
            ];

            $this->logger->info('📊 Fetching klines from BitMart', [
                'symbol' => $symbol,
                'start' => $cursor->format('Y-m-d H:i:s'),
                'end'   => $chunkEnd->format('Y-m-d H:i:s'),
                'step'  => $step
            ]);

            try {
                $response = $this->httpClient->request('GET', self::BASE_URL . '/contract/public/kline', [
                    'query' => $parameters,
                ]);

                $data = $response->toArray(false);

                if (!empty($data['data'])) {
                    foreach ($data['data'] as $row) {
                        $results[] = $row;
                    }
                }

            } catch (\Throwable $e) {
                $this->logger->error('❌ Error fetching klines', [
                    'error' => $e->getMessage(),
                    'start' => $cursor->format('Y-m-d H:i:s'),
                    'end' => $chunkEnd->format('Y-m-d H:i:s'),
                ]);
            }

            $cursor = $chunkEnd;
            sleep(1); // respect du taux limite
        }
        $dtos = [];
        foreach ($results as $result) {
            $dtos[] = KlineDto::fromApi($result);
        }

        return $dtos;
    }

    public function fetchLatestKlines(string $symbol, int $limit = 100, int $step = 1): array
    {
        $end = new \DateTimeImmutable(); // maintenant
        $start = $end->sub(new \DateInterval("PT" . ($limit * $step) . "M"));

        return $this->fetchKlines($symbol, $start, $end, $step);
    }

}
