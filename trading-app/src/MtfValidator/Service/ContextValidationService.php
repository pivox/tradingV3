<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

#[Lazy]
class ContextValidationService
{
    public function __construct(
        private readonly TimeframeValidationService $timeframeValidationService,
    ) {
    }

    /**
     * Valide le contexte MTF (ex: 4h/1h pour regular, 1h/15m pour scalper)
     *
     * @param string[]                              $contextTimeframes
     * @param array<string,mixed>                   $mtfConfig
     * @param array<string,array<string,mixed>>     $indicatorsByTimeframe
     */
    public function validateContext(
        string $symbol,
        ?string $mode,
        array $contextTimeframes,
        array $mtfConfig,
        array $indicatorsByTimeframe,
    ): ContextDecisionDto {
        $decisions = [];

        foreach ($contextTimeframes as $tf) {
            $tfIndicators = $indicatorsByTimeframe[$tf] ?? [];

            $decisions[] = $this->timeframeValidationService->validateTimeframe(
                symbol: $symbol,
                timeframe: $tf,
                phase: 'context',
                mode: $mode,
                mtfConfig: $mtfConfig,
                indicators: $tfIndicators,
            );
        }

        [$isValid, $reason] = $this->evaluateContext($decisions, $mode, $mtfConfig);

        return new ContextDecisionDto(
            isValid: $isValid,
            reasonIfInvalid: $reason,
            timeframeDecisions: $decisions,
        );
    }

    /**
     * Logique de consolidation du contexte.
     *
     * - mode 'pragmatic' (par défaut) :
     *   - aucun TF contexte ne doit être invalid (valid = false)
     *   - au moins un TF doit donner un side non neutral
     *   - tous les sides non neutral doivent être cohérents (tous long ou tous short)
     *
     * - mode 'strict' (si un jour tu l'utilises) :
     *   - tous les TF contexte doivent être valid
     *   - tous les TF contexte doivent avoir un side non neutral
     *   - tous les sides doivent être identiques
     *
     * @param TimeframeDecisionDto[] $decisions
     * @param array<string,mixed>    $mtfConfig
     *
     * @return array{0: bool, 1: ?string}
     */
    private function evaluateContext(
        array $decisions,
        ?string $mode,
        array $mtfConfig,
    ): array {
        if (empty($decisions)) {
            return [false, 'no_context_timeframes'];
        }
        $mode = $mode ?? ($mtfConfig['mode'] ?? 'pragmatic');

        $invalidTfs = [];
        $nonNeutralSides = [];

        foreach ($decisions as $d) {
            if (!$d->valid) {
                $invalidTfs[] = $d->timeframe;
            }

            if (\in_array($d->signal, ['long', 'short'], true)) {
                $nonNeutralSides[$d->timeframe] = $d->signal;
            }
        }

        // Au moins un TF doit exister
        if (\count($decisions) === 0) {
            return [false, 'no_context_decisions'];
        }

        if ($mode === 'strict') {
            // STRICT : aucun TF invalid, tous avec side non neutral et cohérent
            if (!empty($invalidTfs)) {
                return [false, 'strict_context_has_invalid_timeframes'];
            }

            if (\count($nonNeutralSides) !== \count($decisions)) {
                return [false, 'strict_context_requires_non_neutral_all'];
            }

            $uniqueSides = \array_unique(\array_values($nonNeutralSides));
            if (\count($uniqueSides) > 1) {
                return [false, 'strict_context_side_conflict'];
            }

            return [true, null];
        }

        // PRAGMATIC (par défaut)
        // 1) si un TF contexte est invalid → contexte KO
        if (!empty($invalidTfs)) {
            return [false, 'pragmatic_context_has_invalid_timeframes'];
        }

        // 2) s’il n’y a aucun side non neutral → contexte insuffisamment clair
        if (empty($nonNeutralSides)) {
            return [false, 'pragmatic_context_all_neutral'];
        }

        // 3) si les sides non neutral sont en conflit (long + short) → contexte KO
        $uniqueSides = \array_unique(\array_values($nonNeutralSides));
        if (\count($uniqueSides) > 1) {
            return [false, 'pragmatic_context_side_conflict'];
        }

        return [true, null];
    }
}
