<?php

namespace App\Service\Exchange\Bitmart;

use App\Service\Account\Bitmart\BitmartFuturesClient;
use App\Service\Exchange\Bitmart\Dto\ContractDto;
use App\Service\Exchange\Bitmart\Dto\KlineDto;
use App\Service\Exchange\ExchangeFetcherInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BitmartFetcher implements ExchangeFetcherInterface
{

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private $baseUrl,
        private BitmartFuturesClient $futuresClient, // <-- injection du client authentifié
    ) {}

    public function fetchContracts(): array
    {
        $response = $this->httpClient->request('GET', $this->baseUrl .'/contract/public/details');
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

    public function fetchKlines(
        string $symbol,
        \DateTimeInterface|int $startTime,
        \DateTimeInterface|int $endTime,
        int $step = 1,
        array $listDates = []
    ): array
    {
        $start = is_int($startTime)
            ? (new \DateTimeImmutable())->setTimestamp(strlen((string)$startTime) > 10 ? intdiv($startTime, 1000) : $startTime)
            : \DateTimeImmutable::createFromInterface($startTime);

        $end = is_int($endTime)
            ? (new \DateTimeImmutable())->setTimestamp(strlen((string)$endTime) > 10 ? intdiv($endTime, 1000) : $endTime)
            : \DateTimeImmutable::createFromInterface($endTime);
        $maxCount = 500;
        $stepMinutes = $step;
        $chunkDuration = $stepMinutes * $maxCount; // durée maximale d'un appel en minutes
        $results = [];

        $cursor = \DateTimeImmutable::createFromInterface($start);
        while ($cursor < $end) {
            $chunkEnd = $cursor->add(new \DateInterval("PT{$chunkDuration}M"));
            if ($chunkEnd > $end) {
                $chunkEnd = \DateTimeImmutable::createFromInterface($end);
            }

            $parameters = [
                'symbol'     => $symbol,
                'step'       => $step,
                'start_time' => $cursor->getTimestamp(),
                'end_time'   => $chunkEnd->getTimestamp(),
            ];

            $this->logger->info('📊 Fetching klines from BitMart via rate limiter', [
                'symbol' => $symbol,
                'start' => $cursor->format('Y-m-d H:i:s'),
                'end'   => $chunkEnd->format('Y-m-d H:i:s'),
                'step'  => $step
            ]);

            try {
                $response = $this->httpClient->request('GET', $this->baseUrl . '/contract/public/kline', [
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
            sleep(0.3); // respect du taux limite
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


    public function fetchPosition(string $symbol): ?array
    {
        try {
            $rawList = $this->futuresClient->getPositions();
            $raw = null;
            foreach ($rawList['data'] ?? [] as $p) {
                if (($p['symbol'] ?? '') === strtoupper($symbol)) {
                    $raw = $p;
                    break;
                }
            }
            if (!$raw) return null;

            // 1) Détails du contrat (taille de lot)
            $details = $this->httpClient->request('GET', $this->baseUrl.'/contract/public/details', [
                'query' => ['symbol' => strtoupper($symbol)]
            ])->toArray(false);

            $sym = $details['data']['symbols'][0] ?? [];
            $contractSize = isset($sym['contract_size']) ? (float)$sym['contract_size']
                : (isset($sym['contract_value']) ? (float)$sym['contract_value'] : 1.0);
            if ($contractSize <= 0) $contractSize = 1.0;

            // 2) Conversion en quantité réelle
            $lots = (float)($raw['current_amount'] ?? 0);
            $positionUnits = $lots * $contractSize;

            // 3) Normalisation
            $side = ((int)($raw['position_type'] ?? 0) === 1) ? 'LONG'
                : (((int)($raw['position_type'] ?? 0) === 2) ? 'SHORT' : 'UNKNOWN');

            return [
                'symbol'       => strtoupper($symbol),
                'side'         => $side,
                'quantity'     => $positionUnits,        // taille normalisée (units)
                'size'         => $positionUnits,        // <-- alias pour l’ancien code
                'entryPrice'   => (float)($raw['entry_price'] ?? 0),
                'markPrice'    => (float)($raw['mark_price'] ?? 0),
                'leverage'     => (float)($raw['leverage'] ?? 0),
                'margin'       => isset($raw['position_value'], $raw['leverage'])
                    ? ((float)$raw['position_value']) / max((float)$raw['leverage'], 1)
                    : null,
                'liqPrice'     => isset($raw['liq_price']) ? (float)$raw['liq_price'] : null,
                'openTime'     => isset($raw['open_timestamp']) ? (int)$raw['open_timestamp'] : null,
                '_lots'        => (float)($raw['current_amount'] ?? 0),
                '_contractSize'=> $contractSize,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('fetchPosition failed', [
                'symbol' => $symbol,
                'err' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Map BitMart position_type vers un côté exploitable (LONG/SHORT).
     */
    private function mapPositionType(int $type): string
    {
        return match ($type) {
            1 => 'LONG',
            2 => 'SHORT',
            default => 'UNKNOWN',
        };
    }


}
