<?php
declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Promote decision_key to extra for visibility and ensure it's present across records.
 */
#[AutoconfigureTag('monolog.processor')]
final class DecisionKeyProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        // Promote to extra if present in context
        if (isset($record->context['decision_key']) && is_string($record->context['decision_key'])) {
            $record->extra['decision_key'] = $record->context['decision_key'];
        }

        return $record;
    }
}

