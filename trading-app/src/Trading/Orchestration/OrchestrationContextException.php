<?php

declare(strict_types=1);

namespace App\Trading\Orchestration;

/**
 * OBS-003 — Incohérence détectée dans le contexte d'orchestration d'une requête
 * `/api/mtf/run` (en-têtes vs payload, ou champs contradictoires du payload).
 *
 * Fail-closed : plutôt que d'accepter silencieusement une requête contradictoire (et de
 * tracer un lineage faux), on refuse avec un code d'erreur STABLE. Le contrôleur traduit
 * en HTTP 422. Le chemin legacy (sans en-têtes ni doublons) ne déclenche aucun contrôle.
 */
final class OrchestrationContextException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
