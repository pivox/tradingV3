<?php

declare(strict_types=1);

namespace App\Bitmart\Http;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BitmartHttpClientPrivate
{
    private const TIMEOUT = 10.0;

    public function __construct(
        private readonly HttpClientInterface $bitmartFuturesV2,
        private readonly BitmartRequestSigner $signer,
        private readonly string $apiKey,
    ) {}

    /**
     * Envoie une requête privée signée vers BitMart Futures V2.
     * $path doit commencer par '/'.
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $json
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $timestamp = (string) (int) (microtime(true) * 1000);
        
        // Pour POST, utiliser le JSON directement comme payload
        $body = $json !== null ? json_encode($json, JSON_UNESCAPED_SLASHES) : '';
        $signature = $this->signer->sign($timestamp, $body);

        $options = [
            'headers' => [
                'X-BM-KEY' => $this->apiKey,
                'X-BM-TIMESTAMP' => $timestamp,
                'X-BM-SIGN' => $signature,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'query' => $query,
            'timeout' => self::TIMEOUT,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $response = $this->bitmartFuturesV2->request($method, $path, $options);
        return $this->parseBitmartResponse($response);
    }

    /**
     * Concat payload pour signature. Pour BitMart Futures V2 la signature privée
     * requiert timestamp#memo#payload. Ici payload = chemin + querystring + body JSON.
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $json
     */
    private function buildPayload(string $method, string $path, array $query, ?array $json): string
    {
        $qs = http_build_query($query);
        $target = $path.(strlen($qs) ? ('?'.$qs) : '');
        $body = $json !== null ? json_encode($json, JSON_UNESCAPED_SLASHES) : '';
        return strtoupper($method).'\n'.$target.'\n'.$body;
    }

    /**
     * @return array<mixed>
     * @throws TransportExceptionInterface
     */
    private function parseBitmartResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('BitMart HTTP '.$status.': '.$response->getContent(false));
        }

        $json = $response->toArray(false);
        if (!isset($json['code']) || (int) $json['code'] !== 1000) {
            $code = $json['code'] ?? 'unknown';
            $msg  = $json['message'] ?? 'unknown';
            throw new \RuntimeException('BitMart API error: code='.$code.' message='.$msg);
        }
        return $json;
    }
}


