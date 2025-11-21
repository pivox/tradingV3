<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Timeframe;

use App\Common\Enum\Timeframe;
use App\Config\MtfConfigProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\MtfValidator\TimeframeProcessorInterface;
use App\Contract\MtfValidator\Dto\TimeframeResultDto;
use App\Contract\MtfValidator\Dto\ValidationContextDto;
use App\Contract\Provider\MainProviderInterface;
use App\Provider\Bitmart\Service\KlineJsonIngestionService;
use App\Provider\Repository\KlineRepository;
use App\MtfValidator\Repository\MtfAuditRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Repository\MtfStateRepository;
use App\Contract\Signal\SignalValidationServiceInterface;
use App\MtfValidator\Service\MtfTimeService;
use App\MtfValidator\Service\Dto\InternalTimeframeResultDto;
use App\MtfValidator\Service\Dto\ProcessingContextDto;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

abstract class BaseTimeframeService implements TimeframeProcessorInterface
{
    public function __construct(
        protected readonly MtfTimeService $timeService,
        protected readonly KlineRepository $klineRepository,
        protected readonly MtfStateRepository $mtfStateRepository,
        protected readonly MtfSwitchRepository $mtfSwitchRepository,
        protected readonly MtfAuditRepository $mtfAuditRepository,
        protected readonly SignalValidationServiceInterface $signalValidationService,
        protected readonly LoggerInterface $mtfLogger,
        protected readonly MtfConfigProviderInterface $mtfConfig,
        protected readonly KlineProviderInterface $klineProvider,
        protected readonly EntityManagerInterface $entityManager,
        protected readonly ClockInterface $clock,
        protected readonly KlineJsonIngestionService $klineJsonIngestionService,
        protected readonly MainProviderInterface $mainProvider
    ) {
    }

    abstract public function getTimeframe(): Timeframe;

    public function getTimeframeValue(): string
    {
        return $this->getTimeframe()->value;
    }

    public function canProcess(string $timeframe): bool
    {
        return $this->getTimeframe()->value === $timeframe;
    }

    /**
     * Traite la validation pour un timeframe spécifique (interface contract)
     */
    public function processTimeframe(
        string $symbol,
        ValidationContextDto $context
    ): TimeframeResultDto {
        $processingContext = ProcessingContextDto::fromContractContext($symbol, $context);
        $internalResult = $this->processTimeframeInternal($processingContext);
        return $internalResult->toContractDto();
    }

    /**
     * Traite la validation pour un timeframe spécifique (méthode interne)
     */
    public function processTimeframeInternal(
        ProcessingContextDto $context
    ): InternalTimeframeResultDto {
        $timeframe = $this->getTimeframe();
        $symbol = $context->symbol;
        $runId = $context->runId;
        $now = $context->now;
        $collector = $context->collector;
        $forceTimeframeCheck = $context->forceTimeframeCheck;
        $forceRun = $context->forceRun;

        try {
            // Vérification des min_bars AVANT la vérification TOO_RECENT pour désactiver les symboles si nécessaire
            $limit = 270; // fallback
            try {
                $cfg = $this->mtfConfig->getConfig();
                $limit = (int)($cfg['timeframes'][$timeframe->value]['guards']['min_bars'] ?? 220) +2;
            } catch (\Throwable $ex) {
                $this->mtfLogger->error("[MTF] Error loading config for {$timeframe->value}, using default limit", ['error' => $ex->getMessage()]);
            }

            $klines = $this->mainProvider->getKlineProvider()->getKlines($symbol, $timeframe, $limit);


            // Kill switch TF (sauf si force-run est activé)
            if (!$forceRun && !$this->mtfSwitchRepository->canProcessSymbolTimeframe($symbol, $timeframe->value)) {
                $this->auditStep($runId, $symbol, "{$timeframe->value}_KILL_SWITCH_OFF", "{$timeframe->value} kill switch is OFF", ['timeframe' => $timeframe->value, 'force_run' => $forceRun]);
                return new InternalTimeframeResultDto(
                    timeframe: $timeframe->value,
                    status: 'SKIPPED',
                    reason: "{$timeframe->value} kill switch OFF"
                );
            }

            // Fenêtre de grâce (sauf si force-run est activé)
            if (!$forceRun && $this->timeService->isInGraceWindow($now, $timeframe)) {
                $this->mtfLogger->info("[MTF] Grace window active - skip {$timeframe->value}", [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'now' => $now->format('Y-m-d H:i:s'),
                ]);
                return new InternalTimeframeResultDto(
                    timeframe: $timeframe->value,
                    status: 'GRACE_WINDOW',
                    signalSide: 'NONE',
                    reason: "In grace window for {$timeframe->value}"
                );
            }

            // ✅ SUITE NORMALE : Vérifications de fraîcheur, kill switches, etc.
            // Reverser en ordre chronologique ascendant
            usort($klines, fn($a, $b) => $a->openTime <=> $b->openTime);

            // Construire Contract avec symbole (requis par AbstractSignal->buildIndicatorContext)
            $contract = (new \App\Provider\Entity\Contract())->setSymbol($symbol);

            // Construire knownSignals minimal depuis le collector courant
            $known = [];
            foreach ($collector as $c) {
                $known[$c['tf']] = ['signal' => strtoupper((string)($c['signal_side'] ?? 'NONE'))];
            }

            // Validation des signaux (avec skipContextValidation si activé)
            $validationResult = $this->signalValidationService->validate($timeframe->value, $klines, $known, $contract, $context->skipContextValidation);
            // Extraire le signal du timeframe actuel
            $tfLower = strtolower($timeframe->value);
            $validationArray = $validationResult->toArray();
            $signalSide = $validationResult->finalSignalValue();
            $status = $validationResult->status;
            $signalData = $validationArray['signals'][$tfLower] ?? [];

            // Log diagnostic pour timeframes de contexte
            if (in_array($timeframe->value, ['1h', '15m', '4h'], true)) {
                $this->mtfLogger->info("[MTF] Context TF validation result", [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'status' => $status,
                    'signal_side' => $signalSide,
                    'validation_details' => [
                        'failed_long' => $signalData['failed_conditions_long'] ?? [],
                        'failed_short' => $signalData['failed_conditions_short'] ?? [],
                        'conditions_long' => $signalData['conditions_long'] ?? [],
                        'conditions_short' => $signalData['conditions_short'] ?? [],
                    ],
                ]);
            }

            // Extraire les informations de la dernière kline
            $lastKline = end($klines);
            $klineTime = $lastKline ? $lastKline->openTime->format('Y-m-d H:i:s') : null;
            $currentPrice = $signalData['current_price'] ?? null;
            $atr = $signalData['atr'] ?? null;

            if ($status === 'FAILED') {
                // Extraire les listes de conditions depuis le payload du service de signal
                $failedLong  = (array)($signalData['failed_conditions_long']  ?? []);
                $failedShort = (array)($signalData['failed_conditions_short'] ?? []);
                $condsLong   = (array)($signalData['conditions_long']        ?? []);
                $condsShort  = (array)($signalData['conditions_short']       ?? []);

                $this->auditStep($runId, $symbol, "{$timeframe->value}_VALIDATION_FAILED", "{$timeframe->value} validation failed", [
                    'timeframe' => $timeframe->value,
                    'kline_time' => $klineTime,
                    'signal_side' => $signalSide,
                    'current_price' => $currentPrice,
                    'atr' => $atr,
                    'passed' => false,
                    'severity' => 2,
                    // Clés nécessaires aux rapports de stats
                    'failed_conditions_long' => $failedLong,
                    'failed_conditions_short' => $failedShort,
                    'conditions_long' => $condsLong,
                    'conditions_short' => $condsShort,
                    // Agrégat pratique utilisé par certains rapports
                    'conditions_failed' => array_values(array_merge($failedLong, $failedShort)),
                ]);

                return new InternalTimeframeResultDto(
                    timeframe: $timeframe->value,
                    status: 'INVALID',
                    signalSide: $signalSide,
                    klineTime: $klineTime,
                    currentPrice: $currentPrice,
                    atr: $atr,
                    indicatorContext: $signalData['indicator_context'] ?? null,
                    conditionsLong: $condsLong,
                    conditionsShort: $condsShort,
                    failedConditionsLong: $failedLong,
                    failedConditionsShort: $failedShort,
                    reason: "{$timeframe->value} validation failed"
                );
            }

            // Succès (VALIDATED ou PENDING)
            $this->auditStep($runId, $symbol, "{$timeframe->value}_VALIDATION_SUCCESS", "{$timeframe->value} validation successful", [
                'timeframe' => $timeframe->value,
                'signal_side' => $signalSide,
                'kline_time' => $klineTime,
                'current_price' => $currentPrice,
                'atr' => $atr,
                'passed' => true,
                'severity' => 0,
            ]);

            return new InternalTimeframeResultDto(
                timeframe: $timeframe->value,
                status: 'VALID',
                signalSide: $signalSide,
                klineTime: $klineTime,
                currentPrice: $currentPrice,
                atr: $atr,
                indicatorContext: $signalData['indicator_context'] ?? null,
                conditionsLong: $signalData['conditions_long'] ?? [],
                conditionsShort: $signalData['conditions_short'] ?? []
            );

        } catch (\Throwable $ex) {
            $this->mtfLogger->error("[MTF] Exception in {$timeframe->value} processing", [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $ex->getMessage(),
                'trace' => $ex->getTraceAsString()
            ]);

            $this->auditStep($runId, $symbol, "{$timeframe->value}_EXCEPTION", "Exception in {$timeframe->value} processing: " . $ex->getMessage(), [
                'timeframe' => $timeframe->value,
                'error' => $ex->getMessage(),
                'passed' => false,
                'severity' => 3,
            ]);

            return new InternalTimeframeResultDto(
                timeframe: $timeframe->value,
                status: 'ERROR',
                reason: 'Exception in processing: ' . $ex->getMessage(),
                error: [
                    'message' => $ex->getMessage(),
                    'trace' => $ex->getTraceAsString()
                ]
            );
        }
    }

    /**
     * Met à jour l'état du timeframe dans la base de données
     */
    public function updateState(string $symbol, array $result): void
    {
        $state = $this->mtfStateRepository->getOrCreateForSymbol($symbol);
        $timeframe = $this->getTimeframe();

        // Mise à jour spécifique selon le timeframe
        // Convertir kline_time en DateTimeImmutable si c'est une string
        $klineTimeRaw = $result['kline_time'] ?? null;
        $klineTime = null;

        if ($klineTimeRaw !== null) {
            if ($klineTimeRaw instanceof \DateTimeImmutable) {
                $klineTime = $klineTimeRaw;
            } elseif (is_string($klineTimeRaw)) {
                $klineTime = new \DateTimeImmutable($klineTimeRaw);
            }
        }

        match($timeframe) {
            Timeframe::TF_4H => $state->setK4hTime($klineTime)->set4hSide($result['signal_side']),
            Timeframe::TF_1H => $state->setK1hTime($klineTime)->set1hSide($result['signal_side']),
            Timeframe::TF_15M => $state->setK15mTime($klineTime)->set15mSide($result['signal_side']),
            Timeframe::TF_5M => $state->setK5mTime($klineTime)->set5mSide($result['signal_side']),
            Timeframe::TF_1M => $state->setK1mTime($klineTime)->set1mSide($result['signal_side']),
        };
    }

    /**
     * Vérifie l'alignement avec un timeframe parent
     */
    public function checkAlignment(array $currentResult, array $parentResult, string $parentTimeframe): array
    {
        $timeframe = $this->getTimeframe();
        $currentSide = strtoupper((string)($currentResult['signal_side'] ?? 'NONE'));
        $parentSide = strtoupper((string)($parentResult['signal_side'] ?? 'NONE'));

        if ($currentSide !== $parentSide) {
            return [
                'status' => 'INVALID',
                'reason' => "ALIGNMENT_{$timeframe->value}_NE_{$parentTimeframe}",
                'blocking_tf' => $timeframe->value,
                'conditions_long' => $currentResult['conditions_long'] ?? [],
                'conditions_short' => $currentResult['conditions_short'] ?? [],
                'failed_conditions_long' => $currentResult['failed_conditions_long'] ?? [],
                'failed_conditions_short' => $currentResult['failed_conditions_short'] ?? [],
            ];
        }

        return ['status' => 'ALIGNED'];
    }

    /**
     * Log d'audit pour une étape
     */
    protected function auditStep(
        string $runId,
        string $symbol,
        string $step,
        string $message,
        array $context = []
    ): void {
        $audit = new \App\MtfValidator\Entity\MtfAudit();
        $audit->setRunId(\Ramsey\Uuid\Uuid::fromString($runId));
        $audit->setSymbol($symbol);
        $audit->setStep($step);
        $audit->setCause($message);
        $audit->setDetails($context);

        // Définir la sévérité si elle est présente dans le contexte
        if (isset($context['severity'])) {
            $audit->setSeverity($context['severity']);
        }

        // Renseigner timeframe si fourni dans le contexte
        try {
            if (isset($context['timeframe']) && is_string($context['timeframe']) && $context['timeframe'] !== '') {
                $tfVal = strtolower($context['timeframe']);
                $tfEnum = \App\Common\Enum\Timeframe::tryFrom($tfVal);
                if ($tfEnum !== null) {
                    $audit->setTimeframe($tfEnum);
                }
            }
        } catch (\Throwable) {
            // best effort
        }

        // Renseigner candle_open_ts si kline_time présent
        try {
            if (isset($context['kline_time']) && $context['kline_time']) {
                if ($context['kline_time'] instanceof \DateTimeImmutable) {
                    $audit->setCandleOpenTs($context['kline_time']);
                } elseif (is_string($context['kline_time']) && $context['kline_time'] !== '') {
                    $audit->setCandleOpenTs(new \DateTimeImmutable($context['kline_time'], new \DateTimeZone('UTC')));
                }
            }
        } catch (\Throwable) {
            // best effort
        }

        // Persister l'entité via l'EntityManager (best-effort)
        try {
            $em = $this->mtfAuditRepository->getEntityManager();
            $isOpen = true;
            try {
                if (method_exists($em, 'isOpen')) {
                    $isOpen = (bool) $em->isOpen();
                }
            } catch (\Throwable) {
                // si la méthode n'est pas dispo ou jette, on considère ouvert
                $isOpen = true;
            }

            if (!$isOpen) {
                // Eviter de déclencher une nouvelle erreur qui masquerait la cause racine
                $this->mtfLogger->warning('[MTF] EntityManager is closed; skipping audit persist', [
                    'symbol' => $symbol,
                    'step' => $step,
                    'timeframe' => $context['timeframe'] ?? null,
                ]);
                return;
            }

            $em->persist($audit);
            // Note: le flush sera fait à la fin du traitement du symbole
        } catch (\Throwable $persistEx) {
            // Ne pas remonter: l'audit est best-effort, on loggue seulement
            try {
                $this->mtfLogger->warning('[MTF] Failed to persist audit (best-effort)', [
                    'symbol' => $symbol,
                    'step' => $step,
                    'error' => $persistEx->getMessage(),
                ]);
            } catch (\Throwable) {
                // ignore total failure to log
            }
        }
    }
}
