<?php

namespace App\Provider\Bitmart\Http;

use App\Provider\Bitmart\Dto\ContractDto;
use App\Provider\Bitmart\Dto\ListContractDto;
use App\Provider\Bitmart\Dto\ListKlinesDto;
use App\Util\GranularityHelper;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class BitmartHttpClientPublic
{

    use throttleBitmartRequestTrait;

    private const TIMEOUT  = 10.0; // seconds
    private const RETRY_COUNT = 1;
    private const RETRY_DELAY_MS = 200;

    // Chemins d'API BitMart
    private const PATH_SYSTEM_TIME = '/system/time';
    private const PATH_KLINE = '/contract/public/kline';
    private const PATH_DEPTH = '/contract/public/depth';
    private const PATH_MARKET_TRADE = '/contract/public/market-trade';
    private const PATH_MARKPRICE_KLINE = '/contract/public/markprice-kline';
    private const PATH_LEVERAGE_BRACKET = '/contract/public/leverage-bracket';
    private const PATH_CONTRACT_DETAILS = '/contract/public/details';
    private const PATH_CONTRACT_DEPTH = '/contract/public/depth';


    public function __construct(
        #[Autowire(service: 'http_client.bitmart_futures_v2')]
        private readonly HttpClientInterface $bitmartFuturesV2,

        #[Autowire(service: 'http_client.bitmart_system')]
        private readonly HttpClientInterface $bitmartSystem,

        private readonly LockFactory     $lockFactory,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,

        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.bitmart')] private readonly LoggerInterface $bitmartLogger,
    ) {
        $stateDir = $this->projectDir . '/var/bitmart';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0775, true);
        }
        $this->throttleStatePath = $stateDir . '/throttle.timestamp';
        // Nouveau: répertoire des buckets de throttling
        $this->throttleDirPath = $stateDir . '/throttle';
        if (!is_dir($this->throttleDirPath)) {
            @mkdir($this->throttleDirPath, 0775, true);
        }
    }

    /**
     * GET /contract/public/getSystemTime
     * Retourne le timestamp serveur en millisecondes.
     */
    public function getSystemTimeMs(): int
    {
        $attempt = 0;
        do {
            try {
                // Throttle par bucket (PUBLIC_SYSTEM_TIME)
                [$bucketKey, $limit, $windowSec] = $this->rateSpecForPublic(self::PATH_SYSTEM_TIME);
                $this->bitmartLogger->info('[Bitmart] Request', [
                    'method' => 'GET',
                    'path' => self::PATH_SYSTEM_TIME,
                    'bucket' => $bucketKey,
                    'limit' => $limit,
                    'window' => $windowSec,
                ]);
                $this->throttleBucket($this->lockFactory, $bucketKey, $limit, $windowSec);
                $resp = $this->bitmartSystem->request('GET', self::PATH_SYSTEM_TIME, [
                    'timeout' => self::TIMEOUT,
                ]);
                // Mettre à jour les infos de rate depuis headers
                $this->updateBucketFromHeaders($bucketKey, $resp->getHeaders(false));
                $response = $resp->toArray(false);
                if (($response['code'] ?? null) !== 1000 || !isset($response['data']['server_time'])) {
                    throw new RuntimeException('BitMart: /system/time invalide');
                }
                $this->bitmartLogger->info('[Bitmart] Response', [
                    'method' => 'GET',
                    'path' => self::PATH_SYSTEM_TIME,
                    'status' => $resp->getStatusCode(),
                    'code' => $response['code'] ?? null,
                ]);
                return (float) $response['data']['server_time'];
            } catch (TransportExceptionInterface|ServerExceptionInterface|TimeoutExceptionInterface $e) {
                $this->bitmartLogger->error('[Bitmart] Transport error', [
                    'method' => 'GET',
                    'path' => self::PATH_SYSTEM_TIME,
                    'error' => $e->getMessage(),
                ]);
                if ($attempt >= self::RETRY_COUNT) {
                    throw $e;
                }
                usleep(self::RETRY_DELAY_MS * 1000);
            } catch (ClientExceptionInterface $e) {
                $this->bitmartLogger->error('[Bitmart] Client error', [
                    'method' => 'GET',
                    'path' => self::PATH_SYSTEM_TIME,
                    'error' => $e->getMessage(),
                ]);
            } catch (DecodingExceptionInterface $e) {
                $this->bitmartLogger->error('[Bitmart] Decoding error', [
                    'method' => 'GET',
                    'path' => self::PATH_SYSTEM_TIME,
                    'error' => $e->getMessage(),
                ]);
            } catch (RedirectionExceptionInterface $e) {
                $this->bitmartLogger->error('[Bitmart] Redirection error', [
                    'method' => 'GET',
                    'path' => self::PATH_SYSTEM_TIME,
                    'error' => $e->getMessage(),
                ]);
            }
            $attempt++;
        } while ($attempt <= self::RETRY_COUNT);

        throw new RuntimeException('BitMart: /system/time unreachable after retries');
    }

    /**
     * GET /contract/public/kline
     * Récupère des bougies FUTURES clôturées.
     *
     * @param string $symbol ex. 'BTCUSDT'
     * @param int|string $step
     * @param int|null $startTs
     * @param int|null $endTs
     * @param int $limit
     * @return ListKlinesDto
     */
    public function getFuturesKlines(
        string $symbol,
        int|string $step,
        ?int $startTs = null,
        ?int $endTs   = null,
        int $limit   = 100
    ): ListKlinesDto {
        $stepMinutes = GranularityHelper::normalizeToMinutes($step);
       // $nowSec      = (int) floor($this->getSystemTimeMs() / 1000);
        $endTs = $endTs ?? $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
        $nowSec = $endTs;
        [$defaultFrom, $computedLastClose, $stepSec] = $this->computeWindow($stepMinutes, $limit +1, $nowSec);
        $payload = $this->requestJson('GET', self::PATH_KLINE, [
            'symbol'     => $symbol,
            'step'       => $stepMinutes,
            'start_time' => $defaultFrom,
            'end_time'   => $computedLastClose,
        ]);

        if (($payload['code'] ?? null) !== 1000 || !isset($payload['data']) || !\is_array($payload['data'])) {
            $code   = $payload['code'] ?? 'unknown';
            $msg    = $payload['message'] ?? 'unknown';
            $count  = \is_array($payload['data'] ?? null) ? \count($payload['data']) : 0;
            $detail = sprintf(
                'BitMart: réponse kline invalide (symbol=%s, step=%s, start_time=%d, end_time=%d, targetLastClose=%d, data_count=%d, code=%s, message=%s)',
                $symbol,
                (string) $stepMinutes,
                (int) $startTs,
                (int) $defaultFrom,
                (int) $computedLastClose,
                $count,
                (string) $code,
                (string) $msg,
            );
            throw new RuntimeException($detail);
        }

        // Normaliser l'accès au timestamp du dernier élément, quel que soit le type (array indexé/associatif ou DTO)
        $lastIndex = \count($payload['data']) - 1;
        $last = $payload['data'][$lastIndex] ?? null;

        $extractTs = static function(mixed $item): ?int {
            // KlineDto provider
            if ($item instanceof \App\Provider\Bitmart\Dto\KlineDto) {
                return (int) $item->timestamp;
            }
            // Contrat générique KlineDto (provider contract)
            if ($item instanceof \App\Contract\Provider\Dto\KlineDto) {
                return (int) ($item->openTime->getTimestamp() ?? 0);
            }
            // Tableau associatif
            if (\is_array($item)) {
                if (isset($item['timestamp'])) {
                    return (int) $item['timestamp'];
                }
                if (isset($item['open_time'])) {
                    return (int) $item['open_time'];
                }
                // Tableau indexé numériquement (timestamp souvent à l'index 0)
                if (isset($item[0]) && \is_numeric($item[0])) {
                    return (int) $item[0];
                }
            }
            return null;
        };

        $lastTs = $extractTs($last);
        if ($lastTs !== null) {
            $lastOpenTime = (new \DateTimeImmutable('@' . $lastTs))->setTimezone(new \DateTimeZone('UTC'));
            // Comparaison en secondes vs fenêtre en secondes (stepMinutes * 60)
            if ($this->clock->now()->getTimestamp() - $lastOpenTime->getTimestamp() < ($stepMinutes * 60)) {
                // Si la dernière bougie n'est pas encore clôturée, on la retire
                unset($payload['data'][$lastIndex]);
            }
        }

        // NORMALISER les données BitMart (tableau numérique → associatif)
        // BitMart retourne: [timestamp, open_price, high_price, low_price, close_price, volume]
        $normalized = array_map(function($item) {
            // Si déjà un objet DTO, le laisser tel quel
            if (is_object($item)) {
                return $item;
            }
            // Si tableau numérique BitMart, convertir en associatif
            if (is_array($item) && isset($item[0]) && !isset($item['timestamp'])) {
                return [
                    'timestamp' => (int)($item[0] ?? 0),
                    'open_price' => (string)($item[1] ?? '0'),
                    'high_price' => (string)($item[2] ?? '0'),
                    'low_price' => (string)($item[3] ?? '0'),
                    'close_price' => (string)($item[4] ?? '0'),
                    'volume' => (string)($item[5] ?? '0'),
                    'source' => 'REST'
                ];
            }
            // Sinon retourner tel quel (déjà associatif)
            return $item;
        }, $payload['data']);

        // Normaliser la structure retournée en ListKlinesDto
        $list = new ListKlinesDto(\array_values($normalized));
        try {
            $count = count($list->toArray());
            $this->bitmartLogger->info('[Bitmart] Klines fetched', [
                'symbol' => $symbol,
                'step_min' => $stepMinutes,
                'count' => $count,
            ]);
        } catch (\Throwable) {}
        return $list;

    }


    /**
     * GET /contract/public/depth
     * Carnet (niveau agrégé).
     *
     * @return array{bids: array<int, array{0:string,1:string}>, asks: array<int, array{0:string,1:string}>}
     */
    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        $payload = $this->requestJson('GET', self::PATH_DEPTH, [
            'symbol' => $symbol,
            'limit'  => $limit,
        ]);

        $data = $payload['data'] ?? null;
        if (!\is_array($data) || !isset($data['bids'], $data['asks'])) {
            throw new RuntimeException('BitMart: carnet invalide');
        }

        $out = [
            'bids' => $data['bids'],
            'asks' => $data['asks'],
        ];
        try {
            $this->bitmartLogger->info('[Bitmart] OrderBook fetched', [
                'symbol' => $symbol,
                'bids' => is_array($out['bids']) ? count($out['bids']) : 0,
                'asks' => is_array($out['asks']) ? count($out['asks']) : 0,
            ]);
        } catch (\Throwable) {}
        return $out;
    }

    /**
     * GET /contract/public/market-trade
     * Derniers trades.
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        $payload = $this->requestJson('GET', self::PATH_MARKET_TRADE, [
            'symbol' => $symbol,
            'limit'  => $limit,
        ]);
        $data = $payload['data'] ?? [];
        try {
            $this->bitmartLogger->info('[Bitmart] Recent trades fetched', [
                'symbol' => $symbol,
                'count' => is_array($data) ? count($data) : 0,
            ]);
        } catch (\Throwable) {}
        return $data;
    }

    /**
     * GET /contract/public/markprice-kline
     * Retourne la liste des mark price klines (non typée).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMarkPriceKline(string $symbol, int $step = 1, int $limit = 1, ?int $startTime = null, ?int $endTime = null): array
    {
        $query = [
            'symbol' => $symbol,
            'step'   => $step,
            'limit'  => $limit,
        ];
        if ($startTime !== null) {
            $query['start_time'] = $startTime;
        }
        if ($endTime !== null) {
            $query['end_time'] = $endTime;
        }

        $payload = $this->requestJson('GET', self::PATH_MARKPRICE_KLINE, $query);
        $data = $payload['data'] ?? [];
        try {
            $this->bitmartLogger->info('[Bitmart] MarkPrice Klines fetched', [
                'symbol' => $symbol,
                'step_min' => $step,
                'count' => is_array($data) ? count($data) : 0,
            ]);
        } catch (\Throwable) {}
        return \is_array($data) ? $data : [];
    }

    /**
     * GET /contract/public/leverage-bracket
     * @return array<int, array<string, mixed>>
     */
    public function getLeverageBrackets(string $symbol): array
    {
        $payload = $this->requestJson('GET', self::PATH_LEVERAGE_BRACKET, [
            'symbol' => $symbol,
        ]);

        $data = $payload['data'] ?? [];
        if (isset($data['brackets']) && \is_array($data['brackets'])) {
            $br = $data['brackets'];
            try {
                $this->bitmartLogger->info('[Bitmart] Leverage brackets fetched', [
                    'symbol' => $symbol,
                    'count' => is_array($br) ? count($br) : 0,
                ]);
            } catch (\Throwable) {}
            return $br;
        }

        $out = \is_array($data) ? $data : [];
        try {
            $this->bitmartLogger->info('[Bitmart] Leverage brackets fetched', [
                'symbol' => $symbol,
                'count' => is_array($out) ? count($out) : 0,
            ]);
        } catch (\Throwable) {}
        return $out;
    }

    public function getContractDetails(?string $symbol = null): ListContractDto
    {
        $query = [];
        if ($symbol !== null && $symbol !== '') {
            $query['symbol'] = $symbol;
        }

        $payload = $this->requestJson('GET', self::PATH_CONTRACT_DETAILS, $query);

        if (($payload['code'] ?? null) !== 1000 || !isset($payload['data'])) {
            throw new RuntimeException('BitMart: /contract/public/details invalide');
        }

        $symbols = $payload['data']['symbols'] ?? [];
        try {
            $this->bitmartLogger->info('[Bitmart] Contracts fetched', [
                'symbol_filter' => $symbol,
                'count' => is_array($symbols) ? count($symbols) : 0,
            ]);
        } catch (\Throwable) {}
        return new ListContractDto($symbols ?? []);
    }

    /**
     * Méthode interne: effectue la requête + vérifie code/message + decode JSON.
     *
     * @param array<string,string|int> $query
     * @return array<mixed>
     */
    private function requestJson(string $method, string $path, array $query = []): array
    {
        // Throttle par bucket en fonction de l'endpoint
        [$bucketKey, $limit, $windowSec] = $this->rateSpecForPublic($path);
        $this->bitmartLogger->info('[Bitmart] Request', [
            'method' => $method,
            'path' => $path,
            'bucket' => $bucketKey,
            'limit' => $limit,
            'window' => $windowSec,
            'query' => $query,
        ]);
        $this->throttleBucket($this->lockFactory, $bucketKey, $limit, $windowSec);

        try {
            $response = $this->bitmartFuturesV2->request($method, $path, [
                'query' => $query,
                // timeouts de secours si le client n'est pas correctement scopé
                'timeout' => self::TIMEOUT,
            ]);
            // Mise à jour des infos de rate à partir des headers
            $this->updateBucketFromHeaders($bucketKey, $response->getHeaders(false));
            $status = $response->getStatusCode();
            $parsed = $this->parseBitmartResponse($response);
            $this->bitmartLogger->info('[Bitmart] Response', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'code' => $parsed['code'] ?? null,
            ]);
            return $parsed;
        } catch (\Throwable $e) {
            $this->bitmartLogger->error('[Bitmart] Request failed', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Retourne la spec de rate pour un endpoint public donné.
     * @return array{string,int,float} [bucketKey, limit, windowSec]
     */
    private function rateSpecForPublic(string $path): array
    {
        // Defaults Public: 12/2s (IP)
        $limit = 12;
        $window = 2.0;
        $bucket = 'PUBLIC_DEFAULT';

        switch ($path) {
            case self::PATH_CONTRACT_DETAILS:
                $bucket = 'PUBLIC_DETAILS';
                break;
            case self::PATH_DEPTH:
            case self::PATH_CONTRACT_DEPTH:
                $bucket = 'PUBLIC_DEPTH';
                break;
            case self::PATH_KLINE:
                $bucket = 'PUBLIC_KLINE';
                break;
            case self::PATH_MARKPRICE_KLINE:
                $bucket = 'PUBLIC_MARKPRICE_KLINE';
                break;
            case self::PATH_LEVERAGE_BRACKET:
                $bucket = 'PUBLIC_LEVERAGE_BRACKET';
                break;
            case self::PATH_MARKET_TRADE:
                $bucket = 'PUBLIC_MARKET_TRADE';
                break;
            case self::PATH_SYSTEM_TIME:
                $bucket = 'PUBLIC_SYSTEM_TIME';
                break;
        }

        return [$bucket, $limit, $window];
    }

    /**
     * Vérifie le schéma BitMart {code,message,data}.
     *
     * @return array<mixed>
     */
    private function parseBitmartResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("BitMart HTTP $status: ".$response->getContent(false));
        }

        $response = $response->toArray(false);

        if (!isset($response['code']) || (int) $response['code'] !== 1000) {
            $code = $response['code'] ?? 'unknown';
            $msg  = $response['message'] ?? 'unknown';
            throw new RuntimeException("BitMart API error: code=$code message=$msg");
        }

        return $response;
    }

    /**
     * Calque computeWindow() pour déterminer la dernière clôture terminée.
     */
    private function computeWindow(int $stepMinutes, int $limit, ?int $nowTs = null): array
    {
       // Génère toutes les heures/minutes sous forme "HH:MM"
        $times = function (array $hours, array $minutes): array {
            $out = [];
            foreach ($hours as $h) {
                foreach ($minutes as $m) {
                    $out[] = sprintf('%02d:%02d', (int)$h, (int)$m);
                }
            }
            return $out;
        };

        $hours    = range(0, 23);
        $hours4   = array_filter(range(0, 23), fn(int $h) => $h % 4 === 0);
        $minutes5 = range(0, 59, 5);
        $minutes15= range(0, 59, 15);

        $window = [
            240 => $times($hours4, [0]),          // 00:00, 04:00, 08:00, ...
            60  => $times($hours,  [0]),          // 00:00, 01:00, 02:00, ...
            15  => $times($hours,  $minutes15),   // xx:00, xx:15, xx:30, xx:45
            5   => $times($hours,  $minutes5),    // xx:00, xx:05, xx:10, ...
            1   => $times($hours,  range(0, 59)), // xx:00 → xx:59
        ];
        //@todo HMA utiliser $window pour valider les timestamps
        $now = (new \DateTimeImmutable())->setTimestamp($nowTs)->setTimezone(new \DateTimeZone('UTC'));
        $dec = $now->format('s') > 0 ? 1 : 0;
        $hour =  $now->format('H');
        $minute = sprintf('%02d', (int) $now->format('i') - $dec);
        $dateWindow = (new \DateTimeImmutable('now'))->setTimestamp($nowTs)->setTime(23, 59, 0);
        for ($i = count($window[$stepMinutes]) -1 ; $i>=0; $i--) {
            list($h, $m) = explode(':', $window[$stepMinutes][$i]);
            $dateWindow = (new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->setTimestamp($nowTs)->setTime((int)($h), (int)($m), 0);
            if( $dateWindow->getTimestamp() >= $nowTs) {
                unset($window[$stepMinutes][$i]);
                continue;
            }
            break;
        }
        list($h, $m) = [$dateWindow->format('H'), $dateWindow->format('i')];;

    $endTs = $dateWindow->getTimestamp();
        $minToSubtract = $stepMinutes * $limit;
        $start = $dateWindow->modify("- $minToSubtract minutes");
        $startTs = $start->getTimestamp();
        if ($dateWindow->getTimestamp() === $startTs) {
            // Utiliser une opération DateTime explicite pour retrancher exactement stepMinutes minutes
            $startTs = $dateWindow->modify("- {$stepMinutes} minutes")->getTimestamp();
        }


        return [$startTs, $endTs, $stepMinutes * 60];
    }

    private function alignDown(int $timestamp, int $stepSec): int
    {
        return intdiv($timestamp, $stepSec) * $stepSec;
    }

    /**
     * Garantit un minimum de 200ms entre deux requêtes Bitmart.
     */

    /**
     * Récupère tous les contrats disponibles
     * Alias pour getContractDetails() sans symbole spécifique
     */
    public function fetchContracts(): array
    {
        $contractDetails = $this->getContractDetails();
        return $contractDetails->toArray();
    }

    /**
     * Récupère tous les contrats disponibles (alias)
     */
    public function getContracts(): array
    {
        return $this->fetchContracts();
    }

    /**
     * Vérifie la santé de l'API Bitmart
     */
    public function healthCheck(): bool
    {
        try {
            $this->getSystemTimeMs();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Récupère les klines (alias pour getFuturesKlines)
     */
    public function getKlines(
        string $symbol,
        int $step,
        ?int $startTime = null,
        ?int $endTime = null,
        int $limit = 500
    ): array {
        $klinesDto = $this->getFuturesKlines($symbol, $step, $startTime, $endTime, $limit);
        return $klinesDto->toArray();
    }

    /**
     * Récupère les détails d'un contrat spécifique
     */
    public function fetchContractDetails(string $symbol): array
    {
        $contractDetails = $this->getContractDetails($symbol);
        $contracts = $contractDetails->toArray();
        return $contracts[0] instanceof ContractDto  ? $contracts[0]->toArray() : $contracts[0];
    }

    /**
     * Récupère le dernier prix d'un symbole
     */
    public function getLastPrice(string $symbol): ?float
    {
        try {
            $contractDetails = $this->fetchContractDetails($symbol);
            return isset($contractDetails['last_price']) ? (float) $contractDetails['last_price'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Récupère les trades récents d'un symbole
     */
    public function getMarketTrade(string $symbol, int $limit = 100): array
    {
        return $this->getRecentTrades($symbol, $limit);
    }

    public function getOrderBookTop(string $symbol): ?array {

        $response = $this->requestJson('GET', self::PATH_CONTRACT_DEPTH, [
            'symbol' => $symbol,
            'limit'  => 5,
        ]);
        if ($response['code'] !== 1000) {
            throw new RuntimeException('BitMart: '.self::PATH_CONTRACT_DEPTH.' invalide: symbol='.$symbol);
        }
        if (!isset($response['data']['bids'][0][0], $response['data']['asks'][0][0])) {
            return null;
        }
        return [
            'bid' => (float)$response['data']['bids'][0][0],
            'ask' => (float)$response['data']['asks'][0][0],
            'ts'  => (int)($response['data']['timestamp'] ?? 0),
        ];
    }

}
