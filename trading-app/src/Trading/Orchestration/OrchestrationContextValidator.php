<?php

declare(strict_types=1);

namespace App\Trading\Orchestration;

use App\Trading\Service\RunCorrelationId;

/**
 * OBS-003 — Validation fail-closed du contexte d'orchestration d'une requête
 * `/api/mtf/run`. Symfony ne connaît pas la topologie dashboard/set de l'orchestrateur ;
 * il ne peut donc valider que les **contradictions présentes dans la requête elle-même**.
 * On contrôle ce qui est vérifiable et on refuse les incohérences avec un code stable.
 *
 * Limite documentée : la relation set ⇄ dashboard (un set appartient-il bien au dashboard
 * annoncé ?) nécessite les données de l'orchestrateur et n'est PAS vérifiable côté Symfony.
 * Elle reste de la responsabilité de l'orchestrateur (qui émet des en-têtes cohérents).
 *
 * Aucun contrôle n'est déclenché pour le chemin legacy (ni en-têtes, ni doublons) :
 * l'absence d'en-tête conserve le comportement historique (UUID généré en aval).
 */
final class OrchestrationContextValidator
{
    /**
     * @param array<string,mixed> $data Corps de la requête déjà normalisé en tableau.
     *
     * @throws OrchestrationContextException si une incohérence vérifiable est détectée.
     */
    public function validate(
        ?string $runIdHeader,
        ?string $correlationHeader,
        ?string $setHeader,
        ?string $dashboardHeader,
        array $data,
    ): void {
        $runIdHeader = self::clean($runIdHeader);
        $correlationHeader = self::clean($correlationHeader);
        $setHeader = self::clean($setHeader);
        $dashboardHeader = self::clean($dashboardHeader);

        // 1. L'identifiant de corrélation fourni doit correspondre au X-Run-Id canonique.
        if ($runIdHeader !== null && $correlationHeader !== null) {
            $expected = RunCorrelationId::canonical($runIdHeader);
            if (!hash_equals($expected, $correlationHeader)) {
                throw new OrchestrationContextException(
                    'ORCHESTRATION_CORRELATION_MISMATCH',
                    sprintf(
                        'X-Run-Correlation-Id (%s) ne correspond pas à la forme canonique de X-Run-Id (%s).',
                        $correlationHeader,
                        $expected,
                    ),
                );
            }
        }

        // 2. set_id : en-tête vs payload.
        $setBody = self::clean($data['set_id'] ?? $data['orchestration_set_id'] ?? null);
        if ($setHeader !== null && $setBody !== null && $setHeader !== $setBody) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_SET_MISMATCH',
                sprintf('X-Orchestration-Set-Id (%s) contredit set_id du payload (%s).', $setHeader, $setBody),
            );
        }

        // 3. dashboard_id : en-tête vs payload.
        $dashboardBody = self::clean($data['dashboard_id'] ?? $data['orchestration_dashboard_id'] ?? null);
        if ($dashboardHeader !== null && $dashboardBody !== null && $dashboardHeader !== $dashboardBody) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_DASHBOARD_MISMATCH',
                sprintf(
                    'X-Orchestration-Dashboard-Id (%s) contredit dashboard_id du payload (%s).',
                    $dashboardHeader,
                    $dashboardBody,
                ),
            );
        }

        // 4. profile vs mtf_profile (deux sources du même profil dans le payload).
        $profile = self::clean($data['profile'] ?? null);
        $mtfProfile = self::clean($data['mtf_profile'] ?? null);
        if ($profile !== null && $mtfProfile !== null && strcasecmp($profile, $mtfProfile) !== 0) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_PROFILE_MISMATCH',
                sprintf('profile (%s) et mtf_profile (%s) du payload sont contradictoires.', $profile, $mtfProfile),
            );
        }

        // 5. exchange vs cex (alias du même champ).
        $exchange = self::cleanLower($data['exchange'] ?? null);
        $cex = self::cleanLower($data['cex'] ?? null);
        if ($exchange !== null && $cex !== null && $exchange !== $cex) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_EXCHANGE_MISMATCH',
                sprintf('exchange (%s) et cex (%s) du payload sont contradictoires.', $exchange, $cex),
            );
        }

        // 6. market_type vs type_contract (alias du même champ).
        $marketType = self::cleanLower($data['market_type'] ?? null);
        $typeContract = self::cleanLower($data['type_contract'] ?? null);
        if ($marketType !== null && $typeContract !== null && $marketType !== $typeContract) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_MARKET_TYPE_MISMATCH',
                sprintf(
                    'market_type (%s) et type_contract (%s) du payload sont contradictoires.',
                    $marketType,
                    $typeContract,
                ),
            );
        }
    }

    private static function clean(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function cleanLower(mixed $value): ?string
    {
        $clean = self::clean($value);

        return $clean !== null ? strtolower($clean) : null;
    }
}
