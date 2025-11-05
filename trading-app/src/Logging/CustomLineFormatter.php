<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

/**
 * Formateur personnalisé pour les logs structurés
 * Format: timestamp + canal + level + champs clés en key=value
 */
class CustomLineFormatter extends LineFormatter
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.v';
    private const SENSITIVE_KEYS = ['api_key', 'secret', 'password', 'token', 'memo', 'credentials'];

    public function __construct(
        private readonly TraceIdProvider $traceIdProvider
    ) {
        // Format de base (sans les champs optionnels qui seront ajoutés dynamiquement)
        parent::__construct(
            "[%datetime%] %channel%.%level_name% %extra.formatted_fields% msg=\"%message%\"%extra.formatted%\n",
            self::DATE_FORMAT,
            true,
            true
        );
    }

    public function format(LogRecord $record): string
    {
        // Ajouter symbol_trace_id si symbol est présent dans le contexte
        // LogRecord est immutable dans Monolog 3.x, on doit créer un nouveau record
        if (isset($record->context['symbol']) && is_string($record->context['symbol'])) {
            $symbol = $record->context['symbol'];
            $traceId = $this->traceIdProvider->getOrCreate($symbol);
            $context = $record->context;
            $context['symbol_trace_id'] = $traceId;
            $record = $record->with(context: $context);
        }
        
        // Construire les champs principaux (uniquement ceux qui ont des valeurs)
        $context = $record->context;
        $extra = $record->extra;
        $mainFields = [];
        
        // Symbol
        if (!empty($context['symbol'])) {
            $mainFields[] = 'symbol=' . $this->formatValue($context['symbol']);
        }
        
        // Timeframe (peut être dans timeframe ou tf)
        $tf = $context['timeframe'] ?? $context['tf'] ?? null;
        if (!empty($tf)) {
            $mainFields[] = 'tf=' . $this->formatValue($tf);
        }
        
        // Side
        if (!empty($context['side'])) {
            $mainFields[] = 'side=' . $this->formatValue($context['side']);
        }
        
        // State
        if (!empty($context['state'])) {
            $mainFields[] = 'state=' . $this->formatValue($context['state']);
        }
        
        // Decision key
        if (!empty($extra['decision_key'])) {
            $mainFields[] = 'decision_key=' . $this->formatValue($extra['decision_key']);
        }
        
        // Trace ID
        if (!empty($context['symbol_trace_id'])) {
            $mainFields[] = 'trace_id=' . $this->formatValue($context['symbol_trace_id']);
        }
        
        // Joindre les champs principaux
        $extra['formatted_fields'] = !empty($mainFields) ? implode(' ', $mainFields) . ' ' : '';
        
        // Formater le contexte restant en key=value (sans JSON)
        // Exclure les champs déjà affichés individuellement
        $excludedKeys = ['symbol', 'timeframe', 'side', 'state', 'symbol_trace_id', 'tf'];
        $remainingContext = array_filter(
            $context,
            fn($key) => !in_array($key, $excludedKeys),
            ARRAY_FILTER_USE_KEY
        );
        
        // Créer une chaîne formatée key=value pour le contexte restant
        $contextFormatted = '';
        if (!empty($remainingContext)) {
            $parts = [];
            foreach ($remainingContext as $key => $value) {
                if (is_scalar($value) || is_null($value)) {
                    $parts[] = $key . '=' . $this->formatValue($value);
                } elseif (is_array($value)) {
                    // Pour les tableaux, on les aplatit en key=value
                    $flattened = $this->flattenArray($value, $key);
                    if ($flattened) {
                        $parts[] = $flattened;
                    }
                }
            }
            if (!empty($parts)) {
                $contextFormatted = ' ' . implode(' ', $parts);
            }
        }
        
        // Ajouter le contexte formaté dans extra
        $extra['formatted'] = $contextFormatted;
        $record = $record->with(extra: $extra);
        
        $formatted = parent::format($record);
        
        // Masquer les données sensibles dans le contexte
        $formatted = $this->maskSensitiveData($formatted);
        
        return $formatted;
    }
    
    private function formatValue(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value) && (strpos($value, ' ') !== false || strpos($value, '=') !== false)) {
            return '"' . addslashes($value) . '"';
        }
        return (string) $value;
    }
    
    private function flattenArray(array $array, string $prefix = ''): string
    {
        $parts = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            if (is_scalar($value) || is_null($value)) {
                $parts[] = $fullKey . '=' . $this->formatValue($value);
            } elseif (is_array($value)) {
                // Récursion pour les tableaux imbriqués
                $nested = $this->flattenArray($value, $fullKey);
                if ($nested) {
                    $parts[] = $nested;
                }
            }
        }
        return implode(' ', $parts);
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
