<?php
namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use App\Entity\MtfState;
use App\MtfValidator\Entity\MtfAudit;
use App\MtfValidator\Repository\MtfAuditRepository;
use App\Repository\MtfStateRepository;
use App\Repository\SignalRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Logging\TraceIdProvider;
use Ramsey\Uuid\Uuid;

final class MtfResultProjector
{
    /** @var array<string, \App\Entity\Signal> */
    private array $signalCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MtfAuditRepository $mtfAuditRepository,
        private readonly MtfStateRepository $mtfStateRepository,
        private readonly SignalRepository $signalRepository,
        private readonly TraceIdProvider $traceIdProvider,
        // plus tard : service pour les signaux / TradeEntry
    ) {
    }

    public function project(string $runId, MtfRunDto $input, MtfResultDto $result): void
    {
        // Ce service est partagé, on vide donc le cache local à chaque projection.
        $this->signalCache = [];

        // Si l'EntityManager est fermé (suite à une erreur DB précédente),
        // on évite de projeter pour ne pas déclencher EntityManagerClosed.
        if (method_exists($this->em, 'isOpen')) {
            try {
                if (!$this->em->isOpen()) {
                    return;
                }
            } catch (\Throwable) {
                // Best-effort: si isOpen() échoue, on continue comme avant.
            }
        }

        // 1) mtf_audit
        $this->projectAudit($runId, $input, $result);

        // 2) mtf_state
        $this->projectState($runId, $input, $result);

        // 3) signal (à brancher après selon ta table / module Signal)
        $this->projectSignal($runId, $input, $result);
    }

    private function projectAudit(string $runId, MtfRunDto $input, MtfResultDto $result): void
    {
        $audit = new MtfAudit();
        $audit->setRunId(Uuid::fromString($runId));
        $audit->setSymbol($result->symbol);
        $audit->setStep('MTF_RESULT'); // ou 'CONTEXT', 'EXECUTION', etc.
        $audit->setCause($result->finalReason ?? 'OK');
        $audit->setCreatedAt($result->evaluatedAt);

        // Optionnel : timeframe principal choisi (si tradeable)
        if ($result->executionTimeframe !== null) {
            $audit->setTimeframe(\App\Common\Enum\Timeframe::from($result->executionTimeframe));
        }

        // Générer un trace_id cohérent pour le symbole
        $traceId = $this->traceIdProvider->getOrCreate($result->symbol);
        $audit->setTraceId($traceId);

        // Details JSON : on met un résumé exploitable
        $details = [
            'profile'      => $result->profile,
            'mode'         => $result->mode,
            'is_tradable'  => $result->isTradable,
            'side'         => $result->side,
            'execution_tf' => $result->executionTimeframe,
            'final_reason' => $result->finalReason,
            'context'      => [
                'is_valid'  => $result->context->isValid,
                'reason'    => $result->context->reasonIfInvalid,
                'by_tf'     => array_map(
                    fn($tfDecision) => [
                        'tf'        => $tfDecision->timeframe,
                        'phase'     => $tfDecision->phase,
                        'signal'    => $tfDecision->signal,
                        'valid'     => $tfDecision->valid,
                        'reason'    => $tfDecision->invalidReason,
                        'rules_ok'  => $tfDecision->rulesPassed,
                        'rules_ko'  => $tfDecision->rulesFailed,
                    ],
                    $result->context->timeframeDecisions,
                ),
            ],
            'execution'    => [
                'selected_tf' => $result->execution->selectedTimeframe,
                'selected_side' => $result->execution->selectedSide,
                'reason'      => $result->execution->reasonIfNone,
                'by_tf'       => $result->execution->timeframeDecisions,
            ],
            'extra'        => $result->extra,
        ];

        // Injecter timeframe et trace_id dans les détails pour faciliter les requêtes SQL
        if ($result->executionTimeframe !== null) {
            $details['timeframe'] = $result->executionTimeframe;
        }
        $details['trace_id'] = $traceId;

        $audit->setDetails($details);

        // Option : severity (0 = info, 1 = warning, 2 = error)
        $audit->setSeverity($result->isTradable ? 0 : 1);

        $this->em->persist($audit);
        // on ne flush pas ici pour permettre un flush groupé
    }

    private function projectState(string $runId, MtfRunDto $input, MtfResultDto $result): void
    {
        // On récupère ou crée l'état pour le symbole
        $state = $this->mtfStateRepository->getOrCreateForSymbol($result->symbol);

        $evaluatedAt = $result->evaluatedAt;

        // 1) Mettre à jour le contexte (4h, 1h, 15m)
        foreach ($result->context->timeframeDecisions as $decision) {
            $side = $this->normalizeSide($decision->signal, $decision->valid);
            $this->updateStateForTimeframe($state, $decision->timeframe, $evaluatedAt, $side);
        }

        // 2) Mettre à jour l'exécution (5m, 1m, etc.)
        foreach ($result->execution->timeframeDecisions as $decision) {
            $side = $this->normalizeSide($decision->signal, $decision->valid);
            $this->updateStateForTimeframe($state, $decision->timeframe, $evaluatedAt, $side);
        }

        // 3) Persister, flush global fait par MtfValidatorService
        $this->em->persist($state);
    }

    private function projectSignal(string $runId, MtfRunDto $input, MtfResultDto $result): void
    {
        // 1) Contexte : un signal par timeframe
        foreach ($result->context->timeframeDecisions as $decision) {
            $this->maybePersistSignalFromDecision(
                runId: $runId,
                input: $input,
                result: $result,
                decision: $decision,
                phase: 'context',
            );
        }

        // 2) Exécution : un signal par timeframe
        foreach ($result->execution->timeframeDecisions as $decision) {
            $this->maybePersistSignalFromDecision(
                runId: $runId,
                input: $input,
                result: $result,
                decision: $decision,
                phase: 'execution',
            );
        }

        // 3) Signal principal du plan MTF (uniquement si tradable)
        if ($result->isTradable && $result->executionTimeframe !== null && $result->side !== null) {
            $this->persistMainExecutionSignal($runId, $input, $result);
        }
    }

    private function maybePersistSignalFromDecision(
        string $runId,
        MtfRunDto $input,
        MtfResultDto $result,
        \App\Contract\MtfValidator\Dto\TimeframeDecisionDto $decision,
        string $phase,
    ): void {

        // 0) On ignore les signaux neutres (signal = 'neutral')
        if ($decision->signal === 'neutral') {
            return;
        }

        // 1) On respecte ta règle métier : ne pas persister les signaux "cachés"
        $hidden = $decision->extra['hidden'] ?? false;
        if ($hidden === true) {
            return;
        }

        // 2) Convertir le side MTF → enum SignalSide
        $sideEnum = match ($decision->signal) {
            'long'  => \App\Common\Enum\SignalSide::LONG,
            'short' => \App\Common\Enum\SignalSide::SHORT,
            default => \App\Common\Enum\SignalSide::NONE,
        };

        // 3) Construire l'entité Signal
        $meta = [
            'mtf_phase'       => $phase,
            'rules_passed'    => $decision->rulesPassed,
            'rules_failed'    => $decision->rulesFailed,
            'invalid_reason'  => $decision->invalidReason,
            'final_reason'    => $result->finalReason,
            'run_id'          => $runId,
            'profile'         => $result->profile,
            'mode'            => $result->mode,
        ];

        $this->upsertSignal(
            runId: $runId,
            symbol: $result->symbol,
            timeframe: $decision->timeframe,
            evaluatedAt: $result->evaluatedAt,
            side: $sideEnum,
            meta: $meta,
            score: null,
        );
    }


    private function persistMainExecutionSignal(
        string $runId,
        MtfRunDto $input,
        MtfResultDto $result
    ): void {

        $sideEnum = match ($result->side) {
            'long'  => \App\Common\Enum\SignalSide::LONG,
            'short' => \App\Common\Enum\SignalSide::SHORT,
            default => \App\Common\Enum\SignalSide::NONE,
        };

        $meta = [
            'mtf_phase' => 'mtf_execution_plan',
            'side'      => $result->side,
            'tf'        => $result->executionTimeframe,
            'run_id'    => $runId,
            'profile'   => $result->profile,
            'mode'      => $result->mode,
            'reason'    => $result->finalReason,
        ];

        $this->upsertSignal(
            runId: $runId,
            symbol: $result->symbol,
            timeframe: $result->executionTimeframe,
            evaluatedAt: $result->evaluatedAt,
            side: $sideEnum,
            meta: $meta,
            score: null,
        );
    }

    private function normalizeSide(?string $signal, bool $valid): ?string
    {
        if (!$valid) {
            return null;
        }

        return match ($signal) {
            'long', 'short' => $signal,
            default         => null,
        };
    }


    private function updateStateForTimeframe(
        MtfState $state,
        string $timeframe,
        \DateTimeImmutable $evaluatedAt,
        ?string $side
    ): void
    {
        switch ($timeframe) {
            case '4h':
                $state->setK4hTime($evaluatedAt);
                $state->set4hSide($side);
                break;

            case '1h':
                $state->setK1hTime($evaluatedAt);
                $state->set1hSide($side);
                break;

            case '15m':
                $state->setK15mTime($evaluatedAt);
                $state->set15mSide($side);
                break;

            case '5m':
                $state->setK5mTime($evaluatedAt);
                $state->set5mSide($side);
                break;

            case '1m':
                $state->setK1mTime($evaluatedAt);
                $state->set1mSide($side);
                break;

            default:
                // Timeframe non géré dans MtfState → on ignore silencieusement
                break;
        }
    }

    private function upsertSignal(
        string $runId,
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $evaluatedAt,
        \App\Common\Enum\SignalSide $side,
        array $meta,
        ?float $score,
    ): void {
        $timeframeEnum = \App\Common\Enum\Timeframe::from($timeframe);

        $cacheKey = $this->getSignalCacheKey($runId, $symbol, $timeframeEnum, $evaluatedAt);
        if (isset($this->signalCache[$cacheKey])) {
            $signal = $this->signalCache[$cacheKey];
        } else {
            $signal = $this->signalRepository->findOneBy([
                'symbol' => $symbol,
                'timeframe' => $timeframeEnum,
                'klineTime' => $evaluatedAt,
                'runId' => $runId,
            ]) ?? new \App\Entity\Signal();
            $this->signalCache[$cacheKey] = $signal;
        }

        if ($signal->getId() === null) {
            $signal
                ->setSymbol($symbol)
                ->setTimeframe($timeframeEnum)
                ->setKlineTime($evaluatedAt)
                ->setRunId($runId);
        }

        $signal
            ->setSide($side)
            ->setScore($score)
            ->setMeta($meta)
            ->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->em->persist($signal);
    }

    private function getSignalCacheKey(
        string $runId,
        string $symbol,
        \App\Common\Enum\Timeframe $timeframe,
        \DateTimeImmutable $evaluatedAt
    ): string {
        return implode('|', [
            $runId,
            $symbol,
            $timeframe->value,
            $evaluatedAt->format('U-u'),
        ]);
    }
}
