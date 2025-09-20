<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Entity\ContractPipeline;
use App\Entity\Position;
use App\Repository\KlineRepository;
use App\Service\ContractSignalWriter;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Signals\Timeframe\SignalService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class BitmartKlinesCallbackController extends AbstractController
{
    public function __construct(
        private readonly SignalService $signalService
        // NOTE: IndicatorValidatorClient non utilisé ici : garde-le si tu l’emploies dans une autre méthode
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
    ): JsonResponse {

        // 1) Enveloppe
        $envelope = json_decode($request->getContent(), true) ?? [];
        $payload  = $envelope['payload'] ?? [];

        $symbol    = (string) ($payload['contract']  ?? '');
        $timeframe = (string) ($payload['timeframe'] ?? '4h');
        $limit     = (int)    ($payload['limit']     ?? 220);
        $sinceTs   = isset($payload['since_ts']) ? (int) $payload['since_ts'] : null;

        if ($symbol === '') {
            return $this->json(['status' => 'error', 'message' => 'Missing contract symbol'], 400);
        }

        /** @var Contract|null $contract */
        $contract = $em->getRepository(Contract::class)->find($symbol);
        if (!$contract) {
            return $this->json(['status' => 'error', 'message' => 'Contract not found: '.$symbol], 404);
        }

        // 2) Mapping TF → minutes
        $tfToMinutes = [
            '1m'=>1,'3m'=>3,'5m'=>5,'15m'=>15,'30m'=>30,
            '1h'=>60,'2h'=>120,'4h'=>240,
        ];
        $stepMinutes = $tfToMinutes[$timeframe] ?? 240;

        // 3) Fenêtre temporelle
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $minSince = $now->sub(new \DateInterval('PT'.max(0, ($limit - 1) * $stepMinutes).'M'));

        $since = $sinceTs === null
            ? $minSince
            : (new \DateTimeImmutable('@'.$sinceTs))->setTimezone(new \DateTimeZone('UTC'));
        if ($since < $minSince) {
            $since = $minSince;
        }

        // 4) Fetch klines (BitmartFetcher prend DateTimeInterface)
        $dtos = $bitmartFetcher->fetchKlines($symbol, $since, $now, $stepMinutes);

        // 5) Persistance
        $persistedDtos = $persister->persistMany($contract, $dtos, $stepMinutes, true);
        $persistedCount = is_countable($persistedDtos) ? count($persistedDtos) : 0;

        // 6) Récup données récentes pour le TF demandé (PAS '4h' en dur)
        $klines = $klineRepository->findRecentBySymbolAndTimeframe($contract->getSymbol(), $timeframe, max(220, $limit));

        // 7) Évaluation signaux
        $signals = $this->signalService->evaluate($klines, $timeframe);

        // 8) Upsert + décision
        $pipeline = $contractSignalWriter->saveAttempt(
            contract: $contract,
            tf: $timeframe,
            signals: $signals,
            flush: false
        );

        if (!$pipeline) {
            $logger->warning('⚠️ No pipeline found for contract', ['symbol' => $symbol, 'timeframe' => $timeframe]);
            return $this->json([
                'status'    => 'ok',
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'persisted' => $persistedCount,
                'decision'  => null,
            ]);
        }

        if ($pipeline->isToDelete()) {
            $em->remove($pipeline);
            $em->flush();
            $logger->info('🗑️ Pipeline deleted after decision', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'step_minutes' => $stepMinutes,
                'persisted' => $persistedCount,
            ]);
            return $this->json([
                'status'    => 'ok',
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'persisted' => $persistedCount,
                'decision'  => null,
            ]);
        }

        $pipelineService->markAttempt($pipeline);
        $pipelineService->applyDecision($pipeline, $timeframe, $pipeline->isValid());

        // 9) Ouverture position sur 1m (actuel) — ⚠️ simplifié
        if ($pipeline->isValid() && $timeframe === '1m') {
            $side = strtoupper((string)$pipeline->getSignalLongOrShortOrNone());
            if (!in_array($side, [Position::SIDE_LONG, Position::SIDE_SHORT], true)) {
                $side = Position::SIDE_LONG;
            }

            $pos = (new Position())
                ->setContract($contract)
                ->setExchange('bitmart')
                ->setSide($side)
                ->setStatus(Position::STATUS_OPEN)
                ->setAmountUsdt('100') // TODO: remplace par PositionSizer (voir bloc ci-dessous)
                ->setOpenedAt(new \DateTimeImmutable())
                ->setMeta($pipeline->getSignals());

            $em->persist($pos);
            $em->flush();

            $logger->info('[Position] Opened 100 USDT', [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'side' => $side, 'position_id' => $pos->getId(),
            ]);
        }

        $decision = $pipeline->getSignals()[$timeframe] ?? null;

        $logger->info('✅ Klines persisted + decision processed', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'step_minutes' => $stepMinutes,
            'persisted' => $persistedCount,
            'decision' => $decision,
        ]);

        return $this->json([
            'status'    => 'ok',
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'persisted' => $persistedCount,
            'decision'  => $decision,
        ]);
    }
}
