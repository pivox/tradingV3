<?php

declare(strict_types=1);

namespace App\Trading\Orchestration;

use App\Provider\Context\ExchangeContextResolver;
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

        // 1. Identifiant de corrélation : il doit correspondre à la forme canonique du
        // run_id ORIGINAL, que les valeurs viennent des EN-TÊTES ou du CORPS (chemin
        // body-only). Le `RunnerController` transmet désormais `run_id`/`correlation_run_id`
        // du payload ; sans ce contrôle, un payload `run_id=runA` + `correlation_run_id=runB`
        // serait accepté et les lignes lifecycle enregistrées sous `canonical(runA)`,
        // contredisant le 422 annoncé pour une incohérence vérifiable.
        $bodyRun = self::clean($data['run_id'] ?? null);
        $bodyRunOriginal = self::clean($data['original_run_id'] ?? null);
        if ($bodyRun !== null && $bodyRunOriginal !== null && $bodyRun !== $bodyRunOriginal) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_CORRELATION_MISMATCH',
                sprintf('run_id (%s) et original_run_id (%s) du payload sont contradictoires.', $bodyRun, $bodyRunOriginal),
            );
        }
        $effectiveRunId = $runIdHeader ?? $bodyRun ?? $bodyRunOriginal;

        $bodyCorrelation = self::clean($data['correlation_run_id'] ?? null);
        if ($correlationHeader !== null && $bodyCorrelation !== null && !hash_equals($correlationHeader, $bodyCorrelation)) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_CORRELATION_MISMATCH',
                sprintf(
                    'X-Run-Correlation-Id (%s) contredit correlation_run_id du payload (%s).',
                    $correlationHeader,
                    $bodyCorrelation,
                ),
            );
        }
        $effectiveCorrelation = $correlationHeader ?? $bodyCorrelation;

        if ($effectiveRunId !== null && $effectiveCorrelation !== null) {
            $expected = RunCorrelationId::canonical($effectiveRunId);
            if (!hash_equals($expected, $effectiveCorrelation)) {
                throw new OrchestrationContextException(
                    'ORCHESTRATION_CORRELATION_MISMATCH',
                    sprintf(
                        'L\'identifiant de corrélation (%s) ne correspond pas à la forme canonique du run_id (%s).',
                        $effectiveCorrelation,
                        $expected,
                    ),
                );
            }
        }

        // 2. set_id : d'abord les DEUX alias du payload entre eux (set_id vs
        // orchestration_set_id), puis l'en-tête vs le payload. Sans ce contrôle inter-alias,
        // deux valeurs contradictoires seraient silencieusement coalescées (mauvaise
        // attribution du set), alors que le validateur doit fail-closed.
        $setShort = self::clean($data['set_id'] ?? null);
        $setLong = self::clean($data['orchestration_set_id'] ?? null);
        if ($setShort !== null && $setLong !== null && $setShort !== $setLong) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_SET_MISMATCH',
                sprintf('set_id (%s) et orchestration_set_id (%s) du payload sont contradictoires.', $setShort, $setLong),
            );
        }
        $setBody = $setShort ?? $setLong;
        if ($setHeader !== null && $setBody !== null && $setHeader !== $setBody) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_SET_MISMATCH',
                sprintf('X-Orchestration-Set-Id (%s) contredit set_id du payload (%s).', $setHeader, $setBody),
            );
        }

        // 3. dashboard_id : idem — alias du payload entre eux, puis en-tête vs payload.
        $dashboardShort = self::clean($data['dashboard_id'] ?? null);
        $dashboardLong = self::clean($data['orchestration_dashboard_id'] ?? null);
        if ($dashboardShort !== null && $dashboardLong !== null && $dashboardShort !== $dashboardLong) {
            throw new OrchestrationContextException(
                'ORCHESTRATION_DASHBOARD_MISMATCH',
                sprintf(
                    'dashboard_id (%s) et orchestration_dashboard_id (%s) du payload sont contradictoires.',
                    $dashboardShort,
                    $dashboardLong,
                ),
            );
        }
        $dashboardBody = $dashboardShort ?? $dashboardLong;
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

        // 6. market_type vs type_contract (alias du même champ). On NORMALISE les deux
        // via ExchangeContextResolver (perp/future/futures ≡ perpetual) AVANT de comparer,
        // pour ne pas rejeter des alias pourtant équivalents (compat clients legacy qui
        // envoient les deux champs). Une valeur invalide reste refusée fail-closed.
        $marketType = self::clean($data['market_type'] ?? null);
        $typeContract = self::clean($data['type_contract'] ?? null);
        if ($marketType !== null && $typeContract !== null) {
            try {
                $normalizedMarket = ExchangeContextResolver::normalizeMarketType($marketType);
                $normalizedContract = ExchangeContextResolver::normalizeMarketType($typeContract);
            } catch (\InvalidArgumentException $e) {
                throw new OrchestrationContextException(
                    'ORCHESTRATION_MARKET_TYPE_MISMATCH',
                    sprintf(
                        'Type de marché invalide entre market_type (%s) et type_contract (%s) : %s',
                        $marketType,
                        $typeContract,
                        $e->getMessage(),
                    ),
                );
            }

            if ($normalizedMarket !== $normalizedContract) {
                throw new OrchestrationContextException(
                    'ORCHESTRATION_MARKET_TYPE_MISMATCH',
                    sprintf(
                        'market_type (%s) et type_contract (%s) du payload sont contradictoires (%s vs %s).',
                        $marketType,
                        $typeContract,
                        $normalizedMarket->value,
                        $normalizedContract->value,
                    ),
                );
            }
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
