<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Entity\ContractPipeline;
use App\Repository\KlineRepository;
use App\Repository\RuntimeGuardRepository;
use App\Service\ContractSignalWriter;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Signals\Timeframe\SignalService;
use App\Util\TimeframeHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\Trading\PositionOpener;


final class BitmartKlinesCallbackController extends AbstractController
{
    public const LIMIT_KLINES = 260;

    public function __construct(
        private readonly SignalService $signalService
    ) {}

    #[Route('/api/callback/bitmart/get-kline', name: 'bitmart_klines_callback', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntityManagerInterface $em,
        ContractPipelineService $pipelineService,
        BitmartFetcher $bitmartFetcher,
        KlinePersister $persister,
        LoggerInterface $logger,
        ContractSignalWriter $contractSignalWriter,
        KlineRepository $klineRepository,
        PositionOpener $positionOpener,
        RuntimeGuardRepository $runtimeGuardRepository,
    ): JsonResponse {
        if ($runtimeGuardRepository->isPaused()) {
            return new JsonResponse(['status' => 'paused'], 200);
        }
        $envelope = json_decode($request->getContent(), true) ?? [];
        $payload  = $envelope['payload'] ?? [];

        $symbol     = (string) ($payload['contract']   ?? '');
        $timeframe  = strtolower((string) ($payload['timeframe'] ?? '4h'));
        $limit      = (int)    ($payload['limit']      ?? self::LIMIT_KLINES);
        $startTs    = isset($payload['start_ts']) ? (int)$payload['start_ts'] : null;
        $endTs      = isset($payload['end_ts'])   ? (int)$payload['end_ts']   : null;

        if ($startTs !== null && $endTs !== null) {
            $stepMinutes = TimeframeHelper::parseTimeframeToMinutes($timeframe);
            $count = 0;
            $dates = [];
            $listDatesTimestamp = [];
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

        if ($symbol === '') {
            return $this->json(['status' => 'error', 'message' => 'Missing contract symbol'], 400);
        }

        /** @var Contract|null $contract */
        // ✅ chercher par symbol (la PK peut ne pas être le symbol)
        $contract = $em->getRepository(Contract::class)->findOneBy(['symbol' => $symbol]);
        if (!$contract) {
            return $this->json(['status' => 'error', 'message' => 'Contract not found: '.$symbol], 404);
        }
        $listDatesTimestamp = $klineRepository->removeExistingKlines($contract->getSymbol(), $listDatesTimestamp, TimeframeHelper::parseTimeframeToMinutes($timeframe));
        // Si fenêtre vide, on sort proprement (idempotent)
        if ($startTs >= $endTs) {
            $logger->info('No klines to fetch (start >= end)', [
                'symbol' => $symbol,
                'tf' => $timeframe,
                'start' => $payload['start'],
                'end' => $payload['end']
            ]);
            return $this->json([
                'status'    => 'ok',
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'persisted' => 0,
                'window'    => ['start'=>$payload['start'], 'end'=>$payload['end']],
                'signals'   => ['status'=>'NOOP','final'=>['signal'=>'NONE']],
                'decision'  => null,
            ]);
        }
        // =====================================================

        // Fetch & persist STRICTEMENT sur [start, end)
        $listDatesTimestamp = array_map(fn($dt) => $dt->getTimestamp(), $listDatesTimestamp);
        $dtos = $bitmartFetcher->fetchKlines($symbol, $startTs, $endTs, $stepMinutes);

        foreach ($dtos as $key => $dto) {
            if (!in_array($dto->timestamp, $listDatesTimestamp)) {
                unset($dtos[$key]);
            }
        }
        $persistedDtos  = $persister->persistMany($contract, $dtos, $stepMinutes, true); // true: flush
        $persistedCount = is_countable($persistedDtos) ? count($persistedDtos) : 0;

        // Rechargement des klines (closes uniquement)
        $lookback = max(260, $limit);
        $klines = $klineRepository->findRecentBySymbolAndTimeframe($contract->getSymbol(), $timeframe, $lookback);
//        $klines = array_values(array_filter($klines, fn($k) => $k->getTimestamp() < $cutoff));

        // Signaux connus (pipeline) pour ne pas recalculer les TF supérieurs
        $existingPipeline = $em->getRepository(ContractPipeline::class)
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

        // Évaluation du TF courant
        $result = $this->signalService->evaluate($timeframe, $klines, $knownSignals);

        $signalsPayload = $result['signals'] ?? [];
        $signalsPayload[$timeframe] = $signalsPayload[$timeframe] ?? ['signal' => 'NONE'];
        $signalsPayload['final']  = $result['final']  ?? ['signal' => 'NONE'];
        $signalsPayload['status'] = $result['status'] ?? 'UNKNOWN';

        $pipeline = $contractSignalWriter->saveAttempt(
            contract: $contract,
            tf: $timeframe,
            signals: $signalsPayload,
            flush: false
        );
        $signal = $signalsPayload['signal'] ?? $signalsPayload["final"]['signal'];
        if ($pipeline && !($timeframe == '4h' &&  $signal === 'NONE')) {
            $isValid = strtoupper($signalsPayload[$timeframe]['signal'] ?? 'NONE') !== 'NONE';
            $pipelineService->markAttempt($pipeline);
            if ($isValid && in_array($timeframe, ['15m','5m','1m'], true)) {
                $finalSide = strtoupper($signalsPayload['final']['signal'] ?? 'NONE');
                if (in_array($finalSide, ['LONG','SHORT'], true)) {
                    $positionOpener->open(
                        symbol: $symbol,
                        finalSideUpper: $finalSide,
                        timeframe: $timeframe,
                        tfSignal: $signalsPayload[$timeframe] ?? []
                    );
                }
            }
            $pipelineService->applyDecision($pipeline, $timeframe, $isValid);
        }
        if ($pipeline && $pipeline->isToDelete()) {
            $em->remove($pipeline);
            $em->flush();
            $logger->info('Pipeline deleted after decision', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
            ]);
        }



        $logger->info('Klines persisted + evaluated', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'step_minutes' => $stepMinutes,
            'persisted' => $persistedCount,
            'status' => $signalsPayload['status'] ?? null,
            'start' => (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end' => (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        // Si tu préfères retourner KO quand signal = NONE, laisse comme avant :
        if (($signalsPayload[$timeframe]['signal'] ?? 'NONE') === 'NONE') {
            return $this->json([
                'status'    => 'KO',
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'persisted' => $persistedCount,
                'window'    => [
                    'start' => (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'end' => (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ],
                'signals'   => $signalsPayload,
                'decision'  => $signalsPayload['final'],
            ]);
        }

        return $this->json([
            'status'    => 'ok',
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'persisted' => $persistedCount,
            'window'    => [
                'start' => (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end' => (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ],
            'signals'   => $signalsPayload,
            'decision'  => $signalsPayload['final'] ?? null,
        ]);
    }
}
