<?php
// src/Bitmart/Http/BitmartHttpClientPublic.php

declare(strict_types=1);

namespace App\Bitmart\Http;

use App\Dto\ContractDetailsCollection;
use App\Dto\ContractDetailsDto;
use App\Dto\FuturesKlineCollection;
use App\Dto\FuturesKlineDto;
use App\Util\GranularityHelper;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BitmartHttpClientPublic
{
    private const TIMEOUT  = 10.0; // seconds

    public function __construct(
        private readonly HttpClientInterface $bitmartFuturesV2, // http_client.bitmart_futures_v2
        private readonly HttpClientInterface $bitmartSystem,    // http_client.bitmart_system
    ) {}

    /**
     * GET /contract/public/getSystemTime
     * Retourne le timestamp serveur en millisecondes.
     */
    public function getSystemTimeMs(): int
    {
        $resp = $this->bitmartSystem->request('GET', '/system/time');
        $json = $resp->toArray(false);
        if (($json['code'] ?? null) !== 1000 || !isset($json['data']['server_time'])) {
            throw new \RuntimeException('BitMart: /system/time invalide');
        }
        return (int) $json['data']['server_time'];
    }

    /**
     * GET /contract/public/kline
     * Récupère des bougies FUTURES clôturées.
     *
     * @param string $symbol ex. 'BTCUSDT'
     * @param int|string $step
     * @param int|null $fromTs
     * @param int|null $toTs
     * @param int $limit
     * @return FuturesKlineCollection
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getFuturesKlines(
        string $symbol,
        int|string $step,
        ?int $fromTs = null,
        ?int $toTs   = null,
        int $limit   = 100
    ): FuturesKlineCollection {
        $stepMinutes = GranularityHelper::normalizeToMinutes($step);
        $stepSec     = $stepMinutes * 60;
        $nowSec      = (int) floor($this->getSystemTimeMs() / 1000);

        // borne supérieure alignée = début tranche courante
        $currentOpen = intdiv($nowSec, $stepSec) * $stepSec;

        if ($toTs === null) {
            $toTs = $currentOpen; // par défaut jusqu'à maintenant aligné
        }
        if ($fromTs === null) {
            $fromTs = $toTs - $limit * $stepSec;
        }

        $payload = $this->bitmartFuturesV2->request('GET', '/contract/public/kline', [
            'query' => [
                'symbol'     => $symbol,
                'step'       => $stepMinutes,
                'start_time' => $fromTs,
                'end_time'   => $toTs,
            ],
        ])->toArray(false);

        if (($payload['code'] ?? null) !== 1000 || !isset($payload['data']) || !\is_array($payload['data'])) {
            throw new \RuntimeException('BitMart: réponse kline invalide');
        }

        // ⚠️ Filtrage : enlève la bougie courante non close
        $filtered = array_filter($payload['data'], function (array $row) use ($currentOpen) {
            return (int) $row['timestamp'] < $currentOpen;
        });

        $items = array_map(
            static fn(array $row) => FuturesKlineDto::fromApi($row),
            $filtered
        );

        return new FuturesKlineCollection($items);
    }


    /**
     * GET /contract/public/depth
     * Carnet (niveau agrégé).
     *
     * @return array{bids: array<int, array{0:string,1:string}>, asks: array<int, array{0:string,1:string}>}
     */
    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        $payload = $this->requestJson('GET', '/contract/public/depth', [
            'symbol' => $symbol,
            'limit'  => $limit,
        ]);

        $data = $payload['data'] ?? null;
        if (!\is_array($data) || !isset($data['bids'], $data['asks'])) {
            throw new \RuntimeException('BitMart: carnet invalide');
        }

        return [
            'bids' => $data['bids'],
            'asks' => $data['asks'],
        ];
    }

    /**
     * GET /contract/public/market-trade
     * Derniers trades.
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        $payload = $this->requestJson('GET', '/contract/public/market-trade', [
            'symbol' => $symbol,
            'limit'  => $limit,
        ]);
        return $payload['data'] ?? [];
    }

    public function getContractDetails(?string $symbol = null): ContractDetailsCollection
    {
        $query = [];
        if ($symbol !== null && $symbol !== '') {
            $query['symbol'] = $symbol;
        }

        $payload = $this->bitmartFuturesV2->request('GET', '/contract/public/details', [
            'query' => $query,
        ])->toArray(false);

        if (($payload['code'] ?? null) !== 1000 || !isset($payload['data'])) {
            throw new \RuntimeException('BitMart: /contract/public/details invalide');
        }

        $rows = $payload['data']['symbols'] ?? [];

        $items = array_map(
            static fn(array $row) => ContractDetailsDto::fromApi($row),
            $rows
        );

        return new ContractDetailsCollection($items);
    }

    /**
     * Méthode interne: effectue la requête + vérifie code/message + decode JSON.
     *
     * @param array<string,string|int> $query
     * @return array<mixed>
     */
    private function requestJson(string $method, string $path, array $query = []): array
    {
        $response = $this->bitmartFuturesV2->request($method, $path, [
            'query' => $query,
            // timeouts de secours si le client n'est pas correctement scopé
            'timeout' => self::TIMEOUT,
        ]);

        return $this->parseBitmartResponse($response);
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
            throw new \RuntimeException("BitMart HTTP $status: ".$response->getContent(false));
        }

        $json = $response->toArray(false);

        if (!isset($json['code']) || (int) $json['code'] !== 1000) {
            $code = $json['code'] ?? 'unknown';
            $msg  = $json['message'] ?? 'unknown';
            throw new \RuntimeException("BitMart API error: code=$code message=$msg");
        }

        return $json;
    }
}
