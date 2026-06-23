<?php

declare(strict_types=1);

namespace App\Trading\Service;

/**
 * OBS-003 — La source OUTCOME (`position_trade_analysis`) est indisponible.
 *
 * Levée par {@see RunTradeOutcomeService} quand la lecture échoue (vue absente, erreur
 * SQL, EntityManager fermé…). Le contrôleur HTTP la traduit en réponse explicite
 * (`source_available = false`) plutôt qu'en agrégat vide — une indisponibilité ne doit
 * JAMAIS être confondue avec « 0 trade ».
 */
final class PositionTradeOutcomeSourceException extends \RuntimeException
{
    public function __construct(string $message = 'outcome source unavailable', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
