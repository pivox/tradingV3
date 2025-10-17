<?php

declare(strict_types=1);

namespace App\Application\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Psr\Log\LoggerInterface;
use App\Logging\CustomLineFormatter;

/**
 * Activité Temporal pour l'écriture des logs sur filesystem
 * Gère l'écriture réelle des fichiers avec gestion d'erreurs
 */
#[ActivityInterface]
final class LogProcessingActivity
{
    private const LOG_DIR = '/var/log/symfony';
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const MAX_FILES = 14;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Écrit un log unique
     */
    #[ActivityMethod]
    public function writeLog(
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
        $this->rotateLogIfNeeded($logFile);
    }

    /**
     * Écrit un batch de logs
     */
    #[ActivityMethod]
    public function writeLogBatch(array $logs): void
    {
        // Grouper les logs par canal pour optimiser l'écriture
        $logsByChannel = [];
        foreach ($logs as $log) {
            $channel = $log['channel'];
            if (!isset($logsByChannel[$channel])) {
                $logsByChannel[$channel] = [];
            }
            $logsByChannel[$channel][] = $log;
        }

        // Écrire par canal
        foreach ($logsByChannel as $channel => $channelLogs) {
            $logFile = $this->getLogFilePath($channel);
            $formattedLogs = [];

            foreach ($channelLogs as $log) {
                $formattedLogs[] = $this->formatLog(
                    $log['channel'],
                    $log['level'],
                    $log['message'],
                    $log['context'] ?? [],
                    $log['symbol'] ?? null,
                    $log['timeframe'] ?? null,
                    $log['side'] ?? null
                );
            }

            $this->writeToFile($logFile, implode('', $formattedLogs));
            $this->rotateLogIfNeeded($logFile);
        }
    }

    private function getLogFilePath(string $channel): string
    {
        return self::LOG_DIR . '/' . $channel . '.log';
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
        $timestamp = date('Y-m-d H:i:s.v');
        
        // Construire le préfixe structuré
        $prefix = '';
        if ($symbol || $timeframe || $side) {
            $prefix = sprintf('[%s][%s][%s] ', $symbol, $timeframe, $side);
        }

        // Filtrer le contexte pour ne garder que les données non-métadonnées
        $filteredContext = array_filter($context, function($key) {
            return !in_array($key, ['symbol', 'timeframe', 'side']);
        }, ARRAY_FILTER_USE_KEY);

        // Ajouter le contexte JSON si présent
        $contextJson = '';
        if (!empty($filteredContext)) {
            $contextJson = ' ' . json_encode($filteredContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return sprintf(
            "[%s][%s.%s]: %s%s%s\n",
            $timestamp,
            $channel,
            strtoupper($level),
            $prefix,
            $message,
            $contextJson
        );
    }

    private function writeToFile(string $filePath, string $content): void
    {
        // Créer le répertoire si nécessaire
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Écrire avec verrou exclusif
        $written = file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX);
        
        if ($written === false) {
            throw new \RuntimeException("Failed to write log to file: {$filePath}");
        }
    }

    private function rotateLogIfNeeded(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $fileSize = filesize($filePath);
        if ($fileSize < self::MAX_FILE_SIZE) {
            return;
        }

        // Rotation des fichiers
        for ($i = self::MAX_FILES - 1; $i > 0; $i--) {
            $oldFile = $filePath . '.' . $i;
            $newFile = $filePath . '.' . ($i + 1);
            
            if (file_exists($oldFile)) {
                if ($i === self::MAX_FILES - 1) {
                    // Supprimer le plus ancien
                    unlink($oldFile);
                } else {
                    rename($oldFile, $newFile);
                }
            }
        }

        // Renommer le fichier actuel
        rename($filePath, $filePath . '.1');
    }
}

