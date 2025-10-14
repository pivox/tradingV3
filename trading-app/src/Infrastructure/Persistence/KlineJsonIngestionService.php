<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service d'ingestion des klines via fonction SQL JSON
 * 
 * Remplace la méthode Doctrine ORM lente par une insertion directe en SQL
 * utilisant la fonction ingest_klines_json().
 */
final class KlineJsonIngestionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Ingère un batch de klines via la fonction SQL JSON
     * 
     * @param array $klines Array de KlineDto ou array associatif
     * @return IngestionResult Résultat de l'ingestion
     */
    public function ingestKlinesBatch(array $klines): IngestionResult
    {
        if (empty($klines)) {
            return new IngestionResult(0, 0, true);
        }

        $startTime = microtime(true);
        $payload = $this->buildJsonPayload($klines);
        
        try {
            $this->connection->beginTransaction();
            
            $this->connection->executeStatement(
                'SELECT ingest_klines_json(:payload::jsonb)',
                ['payload' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION)]
            );
            
            $this->connection->commit();
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logger->info('[KlineJsonIngestion] Batch ingested successfully', [
                'count' => count($klines),
                'duration_ms' => round($duration, 2),
                'symbol' => $klines[0]['symbol'] ?? $klines[0]->symbol ?? 'unknown',
                'timeframe' => $klines[0]['timeframe'] ?? $klines[0]->timeframe->value ?? 'unknown'
            ]);
            
            return new IngestionResult(
                count: count($klines),
                durationMs: $duration,
                success: true
            );
            
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            
            $this->logger->error('[KlineJsonIngestion] Batch failed', [
                'count' => count($klines),
                'error' => $e->getMessage(),
                'symbol' => $klines[0]['symbol'] ?? $klines[0]->symbol ?? 'unknown'
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Construit le payload JSON pour l'insertion
     * 
     * @param array $klines Array de KlineDto ou array associatif
     * @return array Payload JSON formaté
     */
    private function buildJsonPayload(array $klines): array
    {
        return array_map(function($kline) {
            // Support pour KlineDto (objet) et array associatif
            if (is_object($kline)) {
                return [
                    'symbol' => $kline->symbol,
                    'timeframe' => $kline->timeframe->value,
                    'open_time' => $kline->openTime->format('c'), // ISO 8601
                    'open_price' => (string) $kline->open,
                    'high_price' => (string) $kline->high,
                    'low_price' => (string) $kline->low,
                    'close_price' => (string) $kline->close,
                    'volume' => $kline->volume ? (string) $kline->volume : null,
                    'source' => $kline->source ?? 'REST'
                ];
            } else {
                // Array associatif
                return [
                    'symbol' => $kline['symbol'],
                    'timeframe' => $kline['timeframe'],
                    'open_time' => $kline['open_time'] instanceof \DateTimeInterface 
                        ? $kline['open_time']->format('c') 
                        : $kline['open_time'],
                    'open_price' => (string) $kline['open_price'],
                    'high_price' => (string) $kline['high_price'],
                    'low_price' => (string) $kline['low_price'],
                    'close_price' => (string) $kline['close_price'],
                    'volume' => $kline['volume'] ? (string) $kline['volume'] : null,
                    'source' => $kline['source'] ?? 'REST'
                ];
            }
        }, $klines);
    }
}

/**
 * Résultat de l'ingestion des klines
 */
final class IngestionResult
{
    public function __construct(
        public readonly int $count,
        public readonly float $durationMs,
        public readonly bool $success
    ) {}
}
