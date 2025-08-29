<?php

namespace App\Service\Indicator;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IndicatorValidatorClient
{
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        string $baseUrl,
        int $timeout = 20,
        int $maxRetries = 2
    ) {
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->timeout    = $timeout;
        $this->maxRetries = max(0, $maxRetries);
    }

    /**
     * Appelle POST {baseUrl}/validate avec:
     *  - contract (symbol), timeframe, klines[]
     * Retourne un tableau (ex: ['valid'=>bool, 'side'=>'LONG'|'SHORT', ...]).
     * En cas d’erreur réseau/HTTP, retourne [].
     */
    public function validate(string $symbol, string $timeframe, array $klines): array
    {
        $payload = [
            'contract'  => $symbol,
            'timeframe' => $timeframe,
            'klines'    => $klines,
        ];

        $attempt = 0;
        $delayMs = 250;

        while (true) {
            try {
                $response = $this->http->request('POST', "{$this->baseUrl}/validate", [
                    'json'    => $payload,
                    'timeout' => $this->timeout,
                ]);

                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    $this->logger->warning('[Indicator] HTTP non-2xx', [
                        'status' => $status,
                        'payload' => $payload,
                        'body' => $response->getContent(false),
                    ]);
                    return [];
                }

                $data = $response->toArray(false);
                return $this->sanitizeDecision($data);

            } catch (HttpExceptionInterface|TransportExceptionInterface $e) {
                $attempt++;
                $this->logger->error('[Indicator] validate error', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt > $this->maxRetries) {
                    return [];
                }
                // backoff simple
                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }
    }

    /**
     * Normalise/contrôle minimal la décision renvoyée par l’API.
     * - valid => bool
     * - side  => LONG|SHORT (optionnel)
     * - on laisse passer les autres champs (scores, debug, etc.)
     */
    private function sanitizeDecision(array $data): array
    {
        $decision = [];

        // valid
        $decision['valid'] = filter_var($data['valid'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // side
        if (isset($data['side'])) {
            $side = strtoupper((string)$data['side']);
            if (in_array($side, ['LONG', 'SHORT'], true)) {
                $decision['side'] = $side;
            }
        }

        // merge le reste sans écraser valid/side
        foreach ($data as $k => $v) {
            if (!array_key_exists($k, $decision)) {
                $decision[$k] = $v;
            }
        }

        return $decision;
    }
}
