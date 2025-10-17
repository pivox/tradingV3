<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use App\Logging\LogPublisher;

/**
 * Handler Monolog qui publie les logs vers un worker Temporal
 * au lieu d'écrire directement sur le filesystem
 */
final class TemporalLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogPublisher $logPublisher,
        private readonly bool $enabled = false,
        int $level = 100,
        bool $bubble = true,
        int $bufferSize = 10,
        int $flushIntervalSeconds = 5
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->enabled) {
            return;
        }
        // Utiliser le LogPublisher pour publier directement (avec fallback)
        $this->logPublisher->publishLog(
            $record->channel,
            $record->level->getName(),
            $record->message,
            $record->context,
            $record->context['symbol'] ?? null,
            $record->context['timeframe'] ?? null,
            $record->context['side'] ?? null
        );
    }

    public function close(): void {}
}
