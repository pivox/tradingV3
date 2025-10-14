<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

/**
 * Formateur personnalisé pour les logs structurés
 * Format: [timestamp][channel.LEVEL]: [symbol][timeframe][side] message {context}
 */
class CustomLineFormatter extends LineFormatter
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.v';
    private const SENSITIVE_KEYS = ['api_key', 'secret', 'password', 'token', 'memo', 'credentials'];

    public function __construct()
    {
        parent::__construct(
            "[%datetime%][%channel%.%level_name%]: %message% %context%\n",
            self::DATE_FORMAT,
            true,
            true
        );
    }

    public function format(LogRecord $record): string
    {
        $formatted = parent::format($record);
        
        // Masquer les données sensibles dans le contexte
        $formatted = $this->maskSensitiveData($formatted);
        
        return $formatted;
    }

    /**
     * Masque les données sensibles dans les logs
     */
    private function maskSensitiveData(string $logLine): string
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            // Pattern pour capturer les valeurs sensibles dans le JSON context
            $pattern = '/("' . $key . '":\s*")([^"]+)(")/i';
            $logLine = preg_replace($pattern, '$1***MASKED***$3', $logLine);
        }
        
        return $logLine;
    }

    /**
     * Formate le message avec les métadonnées structurées
     */
    public function formatMessage(LogRecord $record): string
    {
        $message = $record->message;
        $context = $record->context;
        
        // Extraire les métadonnées structurées
        $symbol = $context['symbol'] ?? '';
        $timeframe = $context['timeframe'] ?? '';
        $side = $context['side'] ?? '';
        
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
        
        return $prefix . $message . $contextJson;
    }
}
