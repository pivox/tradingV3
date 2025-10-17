<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Ports\Out\KlineProviderPort;
use App\Infrastructure\Config\BitmartConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use function microtime;
use function usleep;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function round;
use function sprintf;
use function trim;

final class BitmartRestClient implements KlineProviderPort
{
    private const THROTTLE_SECONDS = 0.2;
    private string $throttleStatePath;

    public function __construct(
        private readonly Client          $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface  $clock,
        private readonly BitmartConfig   $config,
        private readonly LockFactory     $lockFactory,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    )
    {
        $stateDir = $this->projectDir . '/var/bitmart';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0775, true);
        }
        $this->throttleStatePath = $stateDir . '/throttle.timestamp';
    }

    public function fetchKlines(string $symbol, Timeframe $timeframe, int $limit = 1000): array
    {
        $step = $timeframe->getStepInMinutes();
        $url = $this->config->getKlinesUrl();

        $endTime = time();
        $startTime = $endTime - ($limit * $step * 60);

        $params = [
            'symbol' => $symbol,
            'step' => $step,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];

        $urlRaw = $url . '?' . http_build_query($params);
        $urlHuman = sprintf(
            '%s?symbol=%s&step=%d&start_time=%s&end_time=%s',
            $url,
            $symbol,
            $step,
            date('Y-m-d H:i:s', $startTime),
            date('Y-m-d H:i:s', $endTime)
        );

        $this->logger->info('BitMart API request', [
            'url_raw' => $urlRaw,
            'url_human' => $urlHuman,
            'params' => $params
        ]);

        $retryCount = 0;
        while ($retryCount < $this->config->getMaxRetries()) {
            try {
                $this->throttleBitmartRequest();
                $response = $this->httpClient->get($url, [
                    'query' => $params,
                    'timeout' => $this->config->getTimeout()
                ]);

                $data = json_decode($response->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);

                if (!isset($data['code']) || $data['code'] !== 1000) {
                    throw new \RuntimeException('BitMart API error: ' . ($data['message'] ?? 'Unknown error'));
                }

                $klinesRaw = $data['data'] ?? [];

                // Log des klines récupérées
                $this->logger->info('[BitmartRestClient] Klines fetched', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'count' => count($klinesRaw),
                    'url' => $urlHuman,
                ]);

                // Mapping DTO
                $klineDtos = [];
                foreach ($klinesRaw as $kline) {
                    $klineDtos[] = new KlineDto(
                        symbol: $symbol,
                        timeframe: $timeframe,
                        openTime: new \DateTimeImmutable('@' . $kline['timestamp'], new \DateTimeZone('UTC')),
                        open: BigDecimal::of($kline['open_price']),
                        high: BigDecimal::of($kline['high_price']),
                        low: BigDecimal::of($kline['low_price']),
                        close: BigDecimal::of($kline['close_price']),
                        volume: BigDecimal::of($kline['volume']),
                        source: 'REST'
                    );
                }

                return $klineDtos;
            } catch (GuzzleException $e) {
                $retryCount++;
                $this->logger->warning('Failed to fetch klines', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'attempt' => $retryCount,
                    'error' => $e->getMessage()
                ]);
                if ($retryCount >= $this->config->getMaxRetries()) {
                    throw new \RuntimeException('Failed to fetch klines after ' . $this->config->getMaxRetries() . ' attempts', 0, $e);
                }
                sleep(2 ** ($retryCount - 1));
            }
        }

        return [];
    }


    /**
     * Récupère les klines depuis l'API REST BitMart sur une fenêtre temporelle explicite
     * sans modifier la signature de l'interface KlineProviderPort.
     *
     * L'API BitMart accepte start_time et end_time (secondes epoch) avec le "step" (minutes).
     * On retourne un tableau de KlineDto trié tel que renvoyé par l'API.
     */
    public function fetchKlinesInWindow(string $symbol, Timeframe $timeframe, \DateTimeImmutable $start, \DateTimeImmutable $end, int $maxLimit = 500): array
    {
        $step = $timeframe->getStepInMinutes();
        $url = $this->config->getKlinesUrl();

        $startTime = $start->getTimestamp();
        $endTime = $end->getTimestamp();

        if ($endTime <= $startTime) {
            return [];
        }

        $params = [
            'symbol' => $symbol,
            'step' => $step,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];

        $urlRaw = $url . '?' . http_build_query($params);
        $urlHuman = sprintf(
            '%s?symbol=%s&step=%d&start_time=%s&end_time=%s',
            $url,
            $symbol,
            $step,
            date('Y-m-d H:i:s', $startTime),
            date('Y-m-d H:i:s', $endTime)
        );

        $this->logger->info('BitMart API request (window)', [
            'url_raw' => $urlRaw,
            'url_human' => $urlHuman,
            'params' => $params
        ]);

        $retryCount = 0;
        while ($retryCount < $this->config->getMaxRetries()) {
            try {
                $this->throttleBitmartRequest();
                $response = $this->httpClient->get($url, [
                    'query' => $params,
                    'timeout' => $this->config->getTimeout(),
                ]);

                $data = json_decode($response->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);
                if (!isset($data['code']) || $data['code'] !== 1000) {
                    throw new \RuntimeException('BitMart API error: ' . ($data['message'] ?? 'Unknown error'));
                }

                $klinesRaw = $data['data'] ?? [];
                if ($maxLimit > 0 && count($klinesRaw) > $maxLimit) {
                    $klinesRaw = array_slice($klinesRaw, -$maxLimit);
                }

                // Log des klines récupérées
                $this->logger->info('[BitmartRestClient] Klines fetched', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'count' => count($klinesRaw),
                    'url' => $urlHuman,
                ]);

                $klineDtos = [];
                foreach ($klinesRaw as $kline) {
                    $klineDtos[] = new KlineDto(
                        symbol: $symbol,
                        timeframe: $timeframe,
                        openTime: new \DateTimeImmutable('@' . $kline['timestamp'], new \DateTimeZone('UTC')),
                        open: BigDecimal::of($kline['open_price']),
                        high: BigDecimal::of($kline['high_price']),
                        low: BigDecimal::of($kline['low_price']),
                        close: BigDecimal::of($kline['close_price']),
                        volume: BigDecimal::of($kline['volume']),
                        source: 'REST'
                    );
                }

                return $klineDtos;
            } catch (GuzzleException $e) {
                $retryCount++;
                $this->logger->warning('Failed to fetch klines (window)', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'attempt' => $retryCount,
                    'error' => $e->getMessage()
                ]);
                if ($retryCount >= $this->config->getMaxRetries()) {
                    throw new \RuntimeException('Failed to fetch klines (window) after ' . $this->config->getMaxRetries() . ' attempts', 0, $e);
                }
                sleep(2 ** ($retryCount - 1));
            }
        }

        return [];
    }


    /**
     * Récupère la liste des contrats disponibles
     */
    public function fetchContracts(): array
    {
        $url = $this->config->getContractsUrl();

        try {
            $this->logger->info('Fetching contracts from BitMart');

            $this->throttleBitmartRequest();
            $response = $this->httpClient->get($url, [
                'timeout' => $this->config->getTimeout()
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['code']) || $data['code'] !== 1000) {
                throw new \RuntimeException('BitMart API error: ' . ($data['message'] ?? 'Unknown error'));
            }

            $contracts = $data['data']['symbols'] ?? [];

            $this->logger->info('Successfully fetched contracts', [
                'count' => count($contracts)
            ]);

            return $contracts;

        } catch (GuzzleException $e) {
            $this->logger->error('Failed to fetch contracts', [
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch contracts', 0, $e);
        }
    }

    /**
     * Récupère les informations d'un contrat spécifique
     */
    public function fetchContractDetails(string $symbol): array
    {
        $url = $this->config->getContractsUrl();

        try {
            $this->logger->info('Fetching contract details from BitMart', ['symbol' => $symbol]);

            $this->throttleBitmartRequest();
            $response = $this->httpClient->get($url, [
                'query' => ['symbol' => $symbol],
                'timeout' => $this->config->getTimeout()
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['code']) || $data['code'] !== 1000) {
                throw new \RuntimeException('BitMart API error: ' . ($data['message'] ?? 'Unknown error'));
            }

            $contract = $data['data']['symbols'][0] ?? null;

            if (!$contract) {
                throw new \RuntimeException('Contract not found: ' . $symbol);
            }

            $this->logger->info('Successfully fetched contract details', ['symbol' => $symbol]);

            return $contract;

        } catch (GuzzleException $e) {
            $this->logger->error('Failed to fetch contract details', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch contract details for ' . $symbol, 0, $e);
        }
    }

    // Implémentation des méthodes de l'interface KlineProviderPort
    // (Ces méthodes seront implémentées avec la persistance)

    public function getWebSocketKlines(string $symbol, Timeframe $timeframe): ?KlineDto
    {
        // Cette méthode sera implémentée avec le WebSocket client
        return null;
    }

    public function saveKline(KlineDto $kline): void
    {
        // Cette méthode sera implémentée avec la persistance
    }

    public function saveKlines(array $klines): void
    {
        // Cette méthode sera implémentée avec la persistance
    }

    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 1000): array
    {
        // Cette méthode sera implémentée avec la persistance
        return [];
    }

    public function getLastKline(string $symbol, Timeframe $timeframe): ?KlineDto
    {
        // Cette méthode sera implémentée avec la persistance
        return null;
    }

    public function hasGaps(string $symbol, Timeframe $timeframe): bool
    {
        // Cette méthode sera implémentée avec la persistance
        return false;
    }

    public function getGaps(string $symbol, Timeframe $timeframe): array
    {
        // Cette méthode sera implémentée avec la persistance
        return [];
    }

    /**
     * Garantit un minimum de 200ms entre deux requêtes Bitmart.
     */
    private function throttleBitmartRequest(): void
    {
        $lock = $this->lockFactory->createLock('bitmart.throttle', 1.0);
        $lock->acquire(true);

        try {
            $now = microtime(true);
            $lastRequest = 0.0;

            if (is_file($this->throttleStatePath)) {
                $raw = trim((string) @file_get_contents($this->throttleStatePath));
                if ($raw !== '') {
                    $lastRequest = (float) $raw;
                }
            }

            if ($lastRequest > 0.0) {
                $elapsed = $now - $lastRequest;
                if ($elapsed < self::THROTTLE_SECONDS) {
                    usleep((int) round((self::THROTTLE_SECONDS - $elapsed) * 1_000_000));
                    $now = microtime(true);
                }
            }

            @file_put_contents($this->throttleStatePath, sprintf('%.6F', $now));
        } finally {
            $lock->release();
        }
    }
}
