<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use App\Repository\KlineRepository;
use App\Repository\RuntimeGuardRepository;
use App\Service\ContractSignalWriter;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Signals\HighConviction\HighConvictionMetricsBuilder;
use App\Service\Signals\Timeframe\SignalService;
use App\Service\Strategy\HighConvictionValidation;
use App\Service\Trading\PositionOpener;
use App\Util\TimeframeHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class KlinesCallbackController extends AbstractController
{
    public const LIMIT_KLINES = 260;

    public function __construct(
        private readonly SignalService $signalService,
        private readonly HighConvictionValidation $highConviction  // <-- injection HC
    ) {}

    #[Route('/api/callback/bitmart/get-kline', name: 'bitmart_klines_callback', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntityManagerInterface $em,
        ContractPipelineService $pipelineService,
        BitmartFetcher $bitmartFetcher,
        KlinePersister $persister,
        LoggerInterface $logger,
        LoggerInterface $validationLogger,
        ContractSignalWriter $contractSignalWriter,
        KlineRepository $klineRepository,
        PositionOpener $positionOpener,
        RuntimeGuardRepository $runtimeGuardRepository,
        ContractPipelineRepository $contractPipelineRepository,
        HighConvictionMetricsBuilder $hcMetricsBuilder,
    ): JsonResponse {
        if ($runtimeGuardRepository->isPaused()) {
            return new JsonResponse(['status' => 'paused'], 200);
        }

        $envelope = json_decode($request->getContent(), true) ?? [];
        $payload  = $envelope['params'] ?? [];

        $symbol     = (string) ($payload['contract']   ?? '');
        $timeframe  = strtolower((string) ($payload['timeframe'] ?? '4h'));
        $limit      = (int)    ($payload['limit']      ?? self::LIMIT_KLINES);
        $startTs    = isset($payload['start_ts']) ? (int)$payload['start_ts'] : null;
        $endTs      = isset($payload['end_ts'])   ? (int)$payload['end_ts']   : null;

        if ($symbol === '') {
            return $this->json(['status' => 'error', 'message' => 'Missing contract symbol'], 400);
        }

        /** @var Contract|null $contract */
        $contract = $em->getRepository(Contract::class)->findOneBy(['symbol' => $symbol]);
        if (!$contract) {
            return $this->json(['status' => 'error', 'message' => 'Contract not found: '.$symbol], 404);
        }

        // Toujours calculer stepMinutes (utilisé plus bas dans les logs)
        $stepMinutes = TimeframeHelper::parseTimeframeToMinutes($timeframe);

        // Fenêtre attendue
        /** @var \DateTimeImmutable[] $listDatesTimestamp */
        $listDatesTimestamp = [];
        if ($startTs !== null && $endTs !== null) {
            $count   = 0;
            $dates   = [];
            $current = (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'));
            $endDate = (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'));
            while ($current <= $endDate) {
                $dates[] = $current->format('Y-m-d H:i:s');
                $listDatesTimestamp[] = $current;
                $current = $current->modify("+{$stepMinutes} minutes");
                $count++;
            }
            $logger->info("Expected klines slots", [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'step_minutes' => $stepMinutes,
                'start' => (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end' => (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'count' => $count,
                'dates' => $dates,
            ]);
        }

        // Idempotence fenêtre vide
        if ($startTs !== null && $endTs !== null && $startTs >= $endTs) {
            $logger->info('No klines to fetch (start >= end)', [
                'symbol' => $symbol,
                'tf' => $timeframe,
                'start' => $payload['start'] ?? null,
                'end' => $payload['end'] ?? null,
            ]);
            return $this->json([
                'status'    => 'ok',
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'persisted' => 0,
                'window'    => ['start'=>$payload['start'] ?? null, 'end'=>$payload['end'] ?? null],
                'signals'   => ['status'=>'NOOP','final'=>['signal'=>'NONE']],
                'decision'  => null,
            ]);
        }

        // Supprime Klines déjà connus (pour garder des slots propres)
        $listDatesTimestamp = $klineRepository->removeExistingKlines(
            $contract->getSymbol(),
            $listDatesTimestamp,
            $stepMinutes
        );

        // Fetch & persist STRICTEMENT sur [start, end)
        $rawSlots = array_map(static fn(\DateTimeImmutable $dt) => $dt->getTimestamp(), $listDatesTimestamp);
        $dtos = $bitmartFetcher->fetchKlines($symbol, $startTs, $endTs, $stepMinutes);
        foreach ($dtos as $key => $dto) {
            if (!in_array($dto->timestamp, $rawSlots, true)) {
                unset($dtos[$key]);
            }
        }
        $persistedDtos  = $persister->persistMany($contract, $dtos, $stepMinutes, true);
        $persistedCount = is_countable($persistedDtos) ? count($persistedDtos) : 0;

        // Rechargement des Klines (closes uniquement)
        $lookback = max(260, $limit);
        $klines   = $klineRepository->findRecentBySymbolAndTimeframe($contract->getSymbol(), $timeframe, $lookback);
        $klines   = array_values($klines);

        // Récupère signaux existants (pour éviter recalcul TF supérieurs)
        $existingPipeline = $em->getRepository(ContractPipeline::class)->findOneBy(['contract' => $contract]);

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

        // Évaluation du TF courant
        $result = $this->signalService->evaluate($timeframe, $klines, $knownSignals);

        $signalsPayload = $result['signals'] ?? [];
        $signalsPayload[$timeframe] = $signalsPayload[$timeframe] ?? ['signal' => 'NONE'];
        $signalsPayload['final']  = $result['final']  ?? ['signal' => 'NONE'];
        $signalsPayload['status'] = $result['status'] ?? 'UNKNOWN';

        // Persistance tentative + plage de Klines
        $pipeline = $contractSignalWriter->saveAttempt(
            contract: $contract,
            tf: $timeframe,
            signals: $signalsPayload,
            flush: false
        );

        $fromKline = $klines ? $klines[0] : null;
        $toKline   = $klines ? $klines[count($klines)-1] : null;
        if ($pipeline && $fromKline && $toKline) {
            $pipeline->setKlineRange($fromKline, $toKline);
        }

        $validationLogger->info(' --- END Evaluating signal '.$timeframe.' --- ');
        $validationLogger->info('signals.payload', [
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

        // Décision MTF + ouverture éventuelle
        $signal = $signalsPayload['signal'] ?? $signalsPayload["final"]['signal'] ?? 'NONE';
        if ($pipeline && !($timeframe === '4h' && $signal === 'NONE')) {

            // 1) Marque tentative & applique décision
            $pipelineService->markAttempt($pipeline);
            $isValid = $pipelineService->applyDecision($pipeline, $timeframe);

            // 2) Log décision
            $validationLogger->info('Position decision', [
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
            ]);

            // 3) Fenêtre d'ouverture (dernier TF seulement)
            $tfOpenable = ['1m'];
            if ($isValid && in_array($timeframe, $tfOpenable, true)) {
                $finalSide = strtoupper($signalsPayload['final']['signal'] ?? 'NONE');

                if (in_array($finalSide, ['LONG','SHORT'], true)) {
                    // 3.a Construit le contexte HC (ctx = contract_pipeline::signals)
                    $ctx = $pipeline->getSignals() ?? [];
                    $finalSide = strtoupper($signalsPayload['final']['signal'] ?? 'NONE');

                    // entry/sl/tp peuvent être NULL ici : le builder gérera les fallbacks
                    $built = $hcMetricsBuilder->buildForSymbol(
                        symbol:     $symbol,
                        signals:    $ctx,
                        sideUpper:  $finalSide,
                        entry:      $limit ?? null,   // si tu as déjà le mark/limit
                        riskMaxPct: 0.07,
                        rMultiple:  2.0
                    );
                    $metrics = $built['metrics'];

                    // 3.c Évalue High Conviction
                    $hc = $this->highConviction->validate($ctx, $metrics);
                    $isHighConv = (bool)($hc['ok'] ?? false);
                    $levCap     = (int)($hc['flags']['leverage_cap'] ?? 0);

                    $validationLogger->info('HighConviction evaluation', [
                        'ok'      => $isHighConv,
                        'flags'   => $hc['flags']   ?? null,
                        'reasons' => $hc['reasons'] ?? null,
                    ]);

                    // 3.d Choix de l’ouvreur selon HC
                    if ($isHighConv && $levCap > 0) {
                        $validationLogger->info('Opening position [HIGH_CONVICTION]', [
                            'symbol'       => $symbol,
                            'final_side'   => $finalSide,
                            'leverage_cap' => $levCap,
                            'signal'       => $signalsPayload[$timeframe] ?? null,
                        ]);

                        $positionOpener->openLimitHighConvWithSr(
                            symbol:         $symbol,
                            finalSideUpper: $finalSide,  // 'LONG' | 'SHORT'
                            leverageCap:    $levCap,     // e.g. 50
                            marginUsdt:     60,
                            riskMaxPct:     0.07,
                            rMultiple:      2.0,
                            meta:           ['ctx' => 'HC'],
                            expireAfterSec: 120
                        );
                    } else {
                        $validationLogger->info('Opening position [SCALPING]', [
                            'symbol'     => $symbol,
                            'final_side' => $finalSide,
                            'signal'     => $signalsPayload[$timeframe] ?? null,
                        ]);

                        $positionOpener->openLimitAutoLevWithSr(
                            symbol:         $symbol,
                            finalSideUpper: $finalSide,
                            marginUsdt:     60,
                            riskMaxPct:     0.07,
                            rMultiple:      2.0
                        );
                    }
                }

                // 3.e Verrouille le pipeline après tentative d’ouverture
                if ($pipeline) {
                    $pipeline->setStatus(ContractPipeline::STATUS_OPENED_LOCKED);
                    $em->flush();
                    $validationLogger->info('Pipeline locked (OPENED_LOCKED)', [
                        'pipeline_id' => $pipeline->getId(),
                        'symbol'      => $symbol,
                    ]);
                }
            }
        }

        // Nettoyage pipeline si demandé
        if ($pipeline && $pipeline->isToDelete() && $pipeline->getId()) {
            $pipelineId = $pipeline->getId();
            $pipeline = $em->getRepository(ContractPipeline::class)->find($pipelineId);
            if ($pipeline) {
                $em->remove($pipeline);
                $em->flush();
                $logger->info('Pipeline deleted after decision', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                ]);
            }
        }

        // Log final
        $logger->info('Klines persisted + evaluated', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'step_minutes' => $stepMinutes,
            'persisted' => $persistedCount,
            'status' => $signalsPayload['status'] ?? null,
            'start' => $startTs ? (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
            'end'   => $endTs   ? (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')   : null,
        ]);

        // Réponse
        if (($signalsPayload[$timeframe]['signal'] ?? 'NONE') === 'NONE') {
            return $this->json([
                'status'    => 'KO',
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'persisted' => $persistedCount,
                'window'    => [
                    'start' => $startTs ? (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
                    'end'   => $endTs   ? (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')   : null,
                ],
                'signals'   => $signalsPayload,
                'decision'  => $signalsPayload['final'] ?? null,
            ]);
        }

        return $this->json([
            'status'    => 'ok',
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'persisted' => $persistedCount,
            'window'    => [
                'start' => $startTs ? (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
                'end'   => $endTs   ? (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')   : null,
            ],
            'signals'   => $signalsPayload,
            'decision'  => $signalsPayload['final'] ?? null,
        ]);
    }
}
