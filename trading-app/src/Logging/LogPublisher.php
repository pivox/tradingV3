<?php

declare(strict_types=1);

namespace App\Logging;

use Psr\Log\LoggerInterface;

/**
 * Service de publication des logs vers Temporal via HTTP API
 * Utilise l'API HTTP de Temporal pour publier les logs de manière asynchrone
 */
final class LogPublisher
{
    private LoggerInterface $logger;
    private array $logBuffer = [];
    private int $bufferSize;
    private int $flushInterval;
    private int $lastFlush;

    public function __construct(
        private readonly TemporalHttpClient $temporalClient,
        LoggerInterface $logger,
        int $bufferSize = 50,
        int $flushIntervalSeconds = 5
    ) {
        $this->logger = $logger;
        $this->bufferSize = $bufferSize;
        $this->flushInterval = $flushIntervalSeconds;
        $this->lastFlush = time();
    }

    /**
     * Publie un log vers Temporal via HTTP API avec fallback synchrone
     */
    public function publishLog(
        string $channel,
        string $level,
        string $message,
        array $context = [],
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $side = null
    ): void {
        try {
            $logData = [
                'channel' => $channel,
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'side' => $side,
            ];

            // Publier via l'API HTTP de Temporal
            $this->temporalClient->publishLog($logData);

        } catch (\Exception $e) {
            // Fallback vers l'écriture synchrone optimisée
            $this->writeLogSync($channel, $level, $message, $context, $symbol, $timeframe, $side);
            
            // Log l'erreur une seule fois pour éviter le spam
            static $loggedError = false;
            if (!$loggedError) {
                $this->logger->warning('Temporal HTTP API unavailable, using synchronous fallback', [
                    'error' => $e->getMessage()
                ]);
                $loggedError = true;
            }
        }
    }

    /**
     * Publie un batch de logs pour optimiser les performances
     */
    public function publishLogBatch(array $logs): void
    {
        if (empty($logs)) {
            return;
        }

        try {
            // Publier le batch via l'API HTTP de Temporal
            $this->temporalClient->publishLogBatch($logs);

        } catch (\Exception $e) {
            // Fallback vers l'écriture synchrone pour chaque log du batch
            foreach ($logs as $log) {
                $this->writeLogSync(
                    $log['channel'],
                    $log['level'],
                    $log['message'],
                    $log['context'] ?? [],
                    $log['symbol'] ?? null,
                    $log['timeframe'] ?? null,
                    $log['side'] ?? null
                );
            }
            
            // Log l'erreur une seule fois pour éviter le spam
            static $loggedBatchError = false;
            if (!$loggedBatchError) {
                $this->logger->warning('Temporal HTTP API unavailable for batch, using synchronous fallback', [
                    'error' => $e->getMessage(),
                    'batch_size' => count($logs)
                ]);
                $loggedBatchError = true;
            }
        }
    }

    /**
     * Ajoute un log au buffer et flush si nécessaire
     */
    public function addToBuffer(
        string $channel,
        string $level,
        string $message,
        array $context = [],
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $side = null
    ): void {
        $this->logBuffer[] = [
            'channel' => $channel,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'side' => $side,
        ];

        $this->checkFlush();
    }

    private function checkFlush(): void
    {
        $shouldFlush = false;

        // Flush si le buffer est plein
        if (count($this->logBuffer) >= $this->bufferSize) {
            $shouldFlush = true;
        }

        // Flush si l'intervalle de temps est écoulé
        if (time() - $this->lastFlush >= $this->flushInterval) {
            $shouldFlush = true;
        }

        if ($shouldFlush) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if (empty($this->logBuffer)) {
            return;
        }

        $this->publishLogBatch($this->logBuffer);
        $this->logBuffer = [];
        $this->lastFlush = time();
    }

    /**
     * Écriture synchrone optimisée en cas de fallback
     */
    private function writeLogSync(
        string $channel,
        string $level,
        string $message,
        array $context = [],
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $side = null
    ): void {
        $logFile = $this->getLogFilePath($channel);
        $formattedLog = $this->formatLog($channel, $level, $message, $context, $symbol, $timeframe, $side);
        
        $this->writeToFile($logFile, $formattedLog);
    }

    private function getLogFilePath(string $channel): string
    {
        return '/var/log/symfony/' . $channel . '.log';
    }

    private function formatLog(
        string $channel,
        string $level,
        string $message,
        array $context = [],
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $side = null
    ): string {
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        
        return sprintf(
            "[%s] %s.%s: %s%s\n",
            $timestamp,
            $channel,
            strtoupper($level),
            $message,
            $contextStr
        );
    }

    private function writeToFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create log directory "%s"', $dir));
        }

        if (file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write log to file "%s"', $filePath));
        }
    }
}