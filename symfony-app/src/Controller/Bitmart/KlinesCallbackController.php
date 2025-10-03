<?php

namespace App\Controller\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Entity\Contract;
use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use App\Repository\KlineRepository;
use App\Service\Exception\Trade\Position\LeverageLowException;
use App\Service\Persister\KlinePersister;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Signals\Timeframe\SignalService;
use App\Service\Trading\PositionOpener;
use App\Service\Trading\BitmartAccountGateway;
use App\Service\Bitmart\BitmartRefreshService;
use App\Service\ContractSignalWriter;
use App\Service\Signals\HighConviction\HighConvictionMetricsBuilder;
use App\Service\Strategy\HighConvictionValidation;
use App\Service\Strategy\HighConvictionTraceWriter;
use App\Repository\RuntimeGuardRepository;
use App\Util\TimeframeHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class KlinesCallbackController extends AbstractController
{
    private const LIMIT_KLINES = 270; // fallback si non fourni

    private readonly HighConvictionValidation $highConviction;
    private readonly BitmartAccountGateway $bitmartAccount;
    private readonly HighConvictionTraceWriter $hcTraceWriter;

    public function __construct(
        // Services et repos conservés
        private readonly EntityManagerInterface $em,
        private readonly ContractPipelineService $pipelineService,
        private readonly ContractSignalWriter $contractSignalWriter,
        private readonly KlineRepository $klineRepository,
        private readonly PositionOpener $positionOpener,
        private readonly RuntimeGuardRepository $runtimeGuardRepository,
        private readonly ContractPipelineRepository $contractPipelineRepository,
        private readonly HighConvictionMetricsBuilder $hcMetricsBuilder,
        HighConvictionValidation $highConviction,
        HighConvictionTraceWriter $hcTraceWriter,
        BitmartAccountGateway $bitmartAccount,

        // ⬇️ Remplacement BitmartFetcher → client public REST
        private readonly BitmartHttpClientPublic $bitmart,

        // Persistance klines via upsert bulk MySQL
        private readonly KlinePersister $persister,

        // Logs
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $validationLogger,

        // Ton service d’évaluation (existant)
        private readonly SignalService $signalService,
        private readonly BitmartRefreshService $refreshService,
    ) {
        $this->highConviction = $highConviction;
        $this->bitmartAccount = $bitmartAccount;
        $this->hcTraceWriter = $hcTraceWriter;
    }

    #[Route('/api/callback/bitmart/get-kline', name: 'bitmart_klines_callback', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->runtimeGuardRepository->isPaused()) {
            return new JsonResponse(['status' => 'paused'], 200);
        }

        $envelope = json_decode($request->getContent(), true) ?? [];
        $payload  = $envelope['params'] ?? [];

        $symbol    = (string) ($payload['contract']  ?? '');
        $timeframe = strtolower((string) ($payload['timeframe'] ?? '4h'));
        $limit     = (int)    ($payload['limit']     ?? self::LIMIT_KLINES);

        if ($symbol === '') {
            return $this->jsonError('Missing contract symbol', 400);
        }

        /** @var Contract|null $contract */
        $contract = $this->em->getRepository(Contract::class)->findOneBy(['symbol' => $symbol]);
        if (!$contract) {
            return $this->jsonError('Contract not found: '.$symbol, 404);
        }

        // Normalise le pas → minutes (Futures V2 attend des minutes côté REST)
        $stepMinutes = TimeframeHelper::parseTimeframeToMinutes($timeframe);

        // 1) Fetch : N DERNIÈRES bougies CLÔTURÉES (exclut la bougie en cours)
        $klinesDto = $this->bitmart->getFuturesKlines(
            symbol: $symbol,
            step:   $stepMinutes,
            fromTs: null,
            toTs:   null,
            limit:  $limit
        );

        // 2) Persist (upsert bulk, clé unique (contract_id, timestamp, step))
        $affected = $this->persister->upsertMany($contract, $stepMinutes, $klinesDto);

        // 3) Recharge un lookback suffisant (closes) pour l’évaluation
        $lookback = max(260, $limit);
        $klines   = $this->klineRepository->findRecentBySymbolAndTimeframe(
            $contract->getSymbol(),
            $timeframe,
            $lookback
        );
        $klines   = array_values($klines);

        // Log de contrôle: compare la dernière kline persistée à l'ouverture de l'intervalle courant (cutoff)
        $cutoff = TimeframeHelper::getAlignedOpenByMinutes($stepMinutes);
        $lastPersisted = null;
        if ($klines) {
            $last = $klines[count($klines) - 1];
            $lastPersisted = method_exists($last, 'getTimestamp') ? $last->getTimestamp() : null;
        }
        $this->logger->info('Klines cutoff control', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'step_minutes' => $stepMinutes,
            'cutoff_ts' => $cutoff->getTimestamp(),
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'last_persisted_ts' => $lastPersisted ? $lastPersisted->getTimestamp() : null,
            'last_persisted' => $lastPersisted ? $lastPersisted->format('Y-m-d H:i:s') : null,
            'has_non_closed' => $lastPersisted ? ($lastPersisted->getTimestamp() >= $cutoff->getTimestamp()) : null,
            'persisted_count' => count($klines),
        ]);

        // Si la dernière kline persistée est plus vieille que now - timeframe, on relance un refresh et on skip
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $staleThreshold = $now->modify('-'.$stepMinutes.' minutes');
        if ($lastPersisted && $lastPersisted < $staleThreshold) {
            $this->logger->warning('Last kline is stale, triggering refresh and skipping evaluation', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'last_persisted' => $lastPersisted->format('Y-m-d H:i:s'),
                'threshold' => $staleThreshold->format('Y-m-d H:i:s'),
            ]);
            $this->refreshService->refreshSingle($symbol, $timeframe, $limit);
            return new JsonResponse([
                'status' => 'refreshed',
                'reason' => 'stale_last_kline',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
            ], 202);
        }

        // 4) Récupère signaux existants (compat avec anciens champs)
        $existingPipeline = $this->em
            ->getRepository(ContractPipeline::class)
            ->findOneBy(['contract' => $contract]);

        $knownSignals = [];
        if ($existingPipeline) {
            $sig = $existingPipeline->getSignals() ?? [];
            foreach (['4h','1h','15m','5m'] as $tf) {
                if (isset($sig[$tf]['signal'])) { $knownSignals[$tf] = $sig[$tf]; }
            }
            $mapCompat = ['context_4h'=>'4h','context_1h'=>'1h','exec_15m'=>'15m','exec_5m'=>'5m','micro_1m'=>'1m'];
            foreach ($mapCompat as $k => $tf) {
                if (!isset($knownSignals[$tf]) && isset($sig[$k]['signal'])) { $knownSignals[$tf] = $sig[$k]; }
            }
        }

        // 5) Évalue le TF courant
        $result = $this->signalService->evaluate($timeframe, $klines, $knownSignals);

        $signalsPayload = $result['signals'] ?? [];
        $signalsPayload[$timeframe] = $signalsPayload[$timeframe] ?? ['signal' => 'NONE'];
        $signalsPayload['final']  = $result['final']  ?? ['signal' => 'NONE'];
        $signalsPayload['status'] = $result['status'] ?? 'UNKNOWN';

        // 6) Sauvegarde des signaux + plage (from/to klines présents)
        $pipeline = $this->contractSignalWriter->saveAttempt(
            contract: $contract,
            tf: $timeframe,
            signals: $signalsPayload,
            flush: false
        );

        $fromKline = $klines ? $klines[0] : null;
        $toKline   = $klines ? $klines[\count($klines)-1] : null;
        if ($pipeline && $fromKline && $toKline) {
            $pipeline->setKlineRange($fromKline, $toKline);
        }

        $this->validationLogger->info(' --- END Evaluating signal '.$timeframe.' --- ');
        $this->validationLogger->info('signals.payload', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'signal' => $signalsPayload[$timeframe]['signal'] ?? 'NONE',
            'signals' => array_map(
                static fn($signal, $key) => "$key => " . ($signal['signal'] ?? 'NONE'),
                $pipeline ? $pipeline->getSignals() : [],
                array_keys($pipeline ? $pipeline->getSignals() : [])
            ),
            'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
            'status' => $signalsPayload['status'] ?? null,
        ]);

        // 7) Décision MTF + ouverture éventuelle
        $signal = $signalsPayload['signal'] ?? $signalsPayload['final']['signal'] ?? 'NONE';
        if ($pipeline && !($timeframe === '4h' && $signal === 'NONE')) {
            $contextTrail = [];

            // a) Marque tentative & applique décision
            $this->pipelineService->markAttempt($pipeline);
            $isValid = $this->pipelineService->applyDecision($pipeline, $timeframe);
            $contextTrail[] = ['step' => 'decision_applied', 'timeframe' => $timeframe, 'is_valid' => $isValid];

            // b) Logs décision
            $this->validationLogger->info('Position decision', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'is_valid' => $isValid,
                'signal' => $signalsPayload[$timeframe]['signal'] ?? 'NONE',
                'signals' => array_map(
                    static fn($signal, $key) => "$key => " . ($signal['signal'] ?? 'NONE'),
                    $pipeline->getSignals(),
                    array_keys($pipeline->getSignals())
                ),
                'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
                'status' => $signalsPayload['status'] ?? null,
                'trail'  => $contextTrail,
            ]);

            // c) Fenêtre d'ouverture (dernier TF seulement)
            $tfOpenable = ['1m'];
            if ($isValid && in_array($timeframe, $tfOpenable, true)) {
                $contextTrail[] = ['step' => 'window_eligible'];
                $this->validationLogger->info('Window eligible for order opening', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'trail' => $contextTrail,
                ]);
                $finalSide = strtoupper($signalsPayload['final']['signal'] ?? 'NONE');

                if (\in_array($finalSide, ['LONG','SHORT'], true)) {
                    $contextTrail[] = ['step' => 'final_side', 'side' => $finalSide];
                    $this->validationLogger->info('Final decision side confirmed', [
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'side' => $finalSide,
                        'trail' => $contextTrail,
                    ]);
                    $ctx = $pipeline->getSignals() ?? [];

                    try {
                        $built = $this->hcMetricsBuilder->buildForSymbol(
                            symbol:     $symbol,
                            signals:    $ctx,
                            sideUpper:  $finalSide,
                            entry:      $limit ?? null,   // si tu utilises déjà un prix/limit ailleurs
                            riskMaxPct: 0.07,
                            rMultiple:  2.0
                        );
                        $metrics = $built['metrics'];
                    } catch (\Throwable $e) {
                        $this->validationLogger->warning('HC metrics builder failed, skipping order', [
                            'symbol' => $symbol,
                            'timeframe' => $timeframe,
                            'error' => $e->getMessage(),
                            'trail' => $contextTrail,
                        ]);
                        $metrics = null;
                    }

                    if ($metrics === null) {
                        $contextTrail[] = ['step' => 'metrics_failed'];
                        goto pipeline_lock;
                    }

                    $hc       = $this->highConviction->validate($ctx, $metrics);
                    $isHigh   = (bool)($hc['ok'] ?? false);
                    $levCap   = (int)($hc['flags']['leverage_cap'] ?? 0);
                    $contextTrail[] = ['step' => 'hc_result', 'is_high' => $isHigh, 'leverage_cap' => $levCap];

                    $this->validationLogger->info('HighConviction evaluation', [
                        'ok'      => $isHigh,
                        'flags'   => $hc['flags']   ?? null,
                        'reasons' => $hc['reasons'] ?? null,
                        'trail'   => $contextTrail,
                    ]);

                    $this->hcTraceWriter->record([
                        'symbol'       => $symbol,
                        'timeframe'    => $timeframe,
                        'evaluated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                        'signals'      => $ctx,
                        'metrics'      => $metrics,
                        'validation'   => $hc,
                        'trail'        => $contextTrail,
                    ]);
                    $this->validationLogger->info('HighConviction trace recorded', [
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                    ]);

                    if ($isHigh && $levCap > 0) {
                        $availableUsdt = $this->bitmartAccount->getAvailableUSDT();
                        $marginBudget  = max(0.0, $availableUsdt * 0.5);
                        $contextTrail[] = [
                            'step' => 'budget_high_conviction',
                            'available_usdt' => $availableUsdt,
                            'margin_usdt' => $marginBudget,
                        ];

                        if ($marginBudget <= 0.0) {
                            $contextTrail[] = ['step' => 'budget_insufficient'];
                            $this->validationLogger->warning('Skipping HC opening: available balance is zero', [
                                'symbol' => $symbol,
                                'available_usdt' => $availableUsdt,
                                'trail' => $contextTrail,
                            ]);
                            goto pipeline_lock;
                        }

                        $contextTrail[] = ['step' => 'opening_high_conviction'];
                        $this->validationLogger->info('Opening position [HIGH_CONVICTION]', [
                            'symbol'       => $symbol,
                            'final_side'   => $finalSide,
                            'leverage_cap' => $levCap,
                            'signal'       => $signalsPayload[$timeframe] ?? null,
                            'trail'        => $contextTrail,
                        ]);

                        try {
                            $this->positionOpener->openLimitHighConvWithSr(
                                symbol:         $symbol,
                                finalSideUpper: $finalSide,
                                leverageCap:    $levCap,
                                marginUsdt:     $marginBudget,
                                riskMaxPct:     0.07,
                                rMultiple:      2.0,
                                meta:           ['ctx' => 'HC'],
                                expireAfterSec: 120
                            );
                            $contextTrail[] = ['step' => 'order_submitted', 'type' => 'high_conviction'];
                            $this->validationLogger->info('Order submitted [HIGH_CONVICTION]', [
                                'symbol' => $symbol,
                                'type' => 'high_conviction',
                                'trail' => $contextTrail,
                            ]);
                        } catch (\Throwable $e) {
                            $insufficient = $this->isInsufficientBalanceError($e);
                            $contextTrail[] = [
                                'step' => 'order_failed',
                                'type' => 'high_conviction',
                                'error' => $e->getMessage(),
                                'insufficient_balance' => $insufficient,
                                'available_usdt' => $availableUsdt,
                                'margin_usdt' => $marginBudget,
                            ];

                            $loggerContext = [
                                'symbol' => $symbol,
                                'error' => $e->getMessage(),
                                'available_usdt' => $availableUsdt,
                                'margin_usdt' => $marginBudget,
                                'trail' => $contextTrail,
                            ];

                            if ($insufficient) {
                                $this->validationLogger->warning('Insufficient balance, skipping order [HIGH_CONVICTION]', $loggerContext);
                            } else {
                                $this->validationLogger->error('Order submission failed [HIGH_CONVICTION]', $loggerContext);
                            }
                        }
                    } else {
                        $contextTrail[] = ['step' => 'opening_scalping'];
                        $this->validationLogger->info('Opening position [SCALPING]', [
                            'symbol'     => $symbol,
                            'final_side' => $finalSide,
                            'signal'     => $signalsPayload[$timeframe] ?? null,
                            'trail'      => $contextTrail,
                        ]);

                        try {
                            $this->positionOpener->openLimitAutoLevWithSr(
                                symbol:         $symbol,
                                finalSideUpper: $finalSide,
                                marginUsdt:     70,
                                riskMaxPct:     0.07,
                                rMultiple:      3.0
                            );
                            $contextTrail[] = ['step' => 'order_submitted', 'type' => 'scalping'];
                            $this->validationLogger->info('Order submitted [SCALPING]', [
                                'symbol' => $symbol,
                                'type' => 'scalping',
                                'trail' => $contextTrail,
                            ]);
                        }
                        catch (LeverageLowException $exception)
                        {
                            $this->validationLogger->error('Leverage balance [SCALPING]', [
                                'symbol' => $symbol,
                                'error' => $exception->getMessage(),
                                'trail' => $contextTrail,
                            ]);
                        }
                        catch (\Throwable $e) {
                            $contextTrail[] = ['step' => 'order_failed', 'type' => 'scalping', 'error' => $e->getMessage()];
                            $this->validationLogger->error('Order submission failed [SCALPING]', [
                                'symbol' => $symbol,
                                'error' => $e->getMessage(),
                                'trail' => $contextTrail,
                            ]);
                        }
                    }
                } else {
                    $contextTrail[] = ['step' => 'final_side_none', 'signal' => $signalsPayload['final']['signal'] ?? 'NONE'];
                    $this->validationLogger->info('Final decision not actionable', [
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
                        'trail' => $contextTrail,
                    ]);
                }

pipeline_lock:
                if ($pipeline) {
                    $contextTrail[] = ['step' => 'pipeline_locked'];
                    $pipeline->setStatus(\App\Entity\ContractPipeline::STATUS_OPENED_LOCKED);
                    $this->em->flush();
                    $this->validationLogger->info('Pipeline locked (OPENED_LOCKED)', [
                        'pipeline_id' => $pipeline->getId(),
                        'symbol'      => $symbol,
                        'trail'       => $contextTrail,
                    ]);
                }
            } elseif ($isValid) {
                $contextTrail[] = ['step' => 'window_not_eligible'];
                $this->validationLogger->info('Window not eligible for opening despite valid decision', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
                    'trail' => $contextTrail,
                ]);
            }
        }

        // 8) Nettoyage pipeline si demandé
        if ($pipeline && $pipeline->isToDelete() && $pipeline->getId()) {
            $pipelineId = $pipeline->getId();
            $pipeline = $this->em->getRepository(\App\Entity\ContractPipeline::class)->find($pipelineId);
            if ($pipeline) {
                $this->em->remove($pipeline);
                $this->em->flush();
                $this->logger->info('Pipeline deleted after decision', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                ]);
            }
        }

        // Log final (pas de start/end désormais)
        $this->logger->info('Klines persisted + evaluated', [
            'symbol'       => $symbol,
            'timeframe'    => $timeframe,
            'step_minutes' => $stepMinutes,
            'affected'     => $affected,
        ]);

        // Réponse
        if (($signalsPayload[$timeframe]['signal'] ?? 'NONE') === 'NONE') {
            return new JsonResponse([
                'status'    => 'KO',
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'persisted' => $affected,
                'window'    => ['limit' => $limit],
                'signals'   => $signalsPayload,
                'decision'  => $signalsPayload['final'] ?? null,
            ]);
        }

        return new JsonResponse([
            'status'    => 'ok',
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'persisted' => $affected,
            'window'    => ['limit' => $limit],
            'signals'   => $signalsPayload,
            'decision'  => $signalsPayload['final'] ?? null,
        ]);
    }

    private function isInsufficientBalanceError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'insufficient balance')
            || str_contains($message, 'balance not sufficient')
            || str_contains($message, 'balance not enough')
            || str_contains($message, 'insufficient margin')
            || str_contains($message, 'margin not enough');
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $status);
    }
}
