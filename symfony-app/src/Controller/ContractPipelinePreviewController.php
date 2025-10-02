<?php
// src/Controller/ContractPipelinePreviewController.php

namespace App\Controller;

use App\Entity\ContractPipeline;
use App\Entity\Kline;
use App\Repository\ContractPipelineRepository;
use App\Repository\KlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class ContractPipelinePreviewController extends AbstractController
{
    /** Map timeframe -> step (secondes) */
    private const TF_TO_STEP = [
        ContractPipeline::TF_4H  => 4 * 3600,
        ContractPipeline::TF_1H  => 3600,
        ContractPipeline::TF_15M => 15 * 60,
        ContractPipeline::TF_5M  => 5 * 60,
        ContractPipeline::TF_1M  => 60,
    ];

    #[Route('/pipelines/with-klines', name: 'pipelines_with_klines', methods: ['GET'])]
    public function __invoke(
        EntityManagerInterface $em,
        KlineRepository $klineRepo
    ): JsonResponse {
        /** @var ContractPipelineRepository $pipelineRepo */
        $pipelineRepo = $em->getRepository(ContractPipeline::class);

        // Équivalent de ta requête (status <> OPENED_LOCKED AND retries = 0 LIMIT 5)
        $qb = $pipelineRepo->createQueryBuilder('cp')
            ->leftJoin('cp.fromKline', 'fromK')
            ->leftJoin('cp.toKline',   'toK')
            ->addSelect('fromK', 'toK')
            ->where('cp.status <> :locked')
            ->andWhere('cp.retries = 0')
            ->andWhere('cp.fromKline IS NOT NULL')
            ->setParameter('locked', ContractPipeline::STATUS_OPENED_LOCKED)
            ->setMaxResults(5);
//        dd($qb->getQuery()->getSQL());

        /** @var ContractPipeline[] $pipelines */
        $pipelines = $qb->getQuery()->getResult();

        $out = [];
        foreach ($pipelines as $p) {
            $contract = $p->getContract();
            $symbol   = $contract->getSymbol();

            // Récup des bornes temps depuis les entités liées
            $fromTs = $p->getFromKline()?->getTimestamp()?->getTimestamp();
            $toTs   = $p->getToKline()?->getTimestamp()?->getTimestamp();

            // fallback défensif si une des bornes manque
            if ($fromTs === null || $toTs === null) {
                // on peut ignorer, ou mettre un range vide
                $start = $end = null;
            } else {
                // Sécurise l’ordre croissant
                $start = min($fromTs, $toTs);
                $end   = max($fromTs, $toTs);
            }

            // Timeframes "existantes" = celles présentes dans signals
            $signals = $p->getSignals() ?? [];
            $tfs = array_keys($signals);

            // Charge les klines par TF présent dans signals
            $klinesByTf = [];
            foreach ($tfs as $tf) {
                $step = self::TF_TO_STEP[$tf] ?? null;
                if ($step === null || $start === null || $end === null) {
                    $klinesByTf[$tf] = [];
                    continue;
                }

                $klines = $klineRepo->findByContractSymbolAndRangeAndStep(
                    $symbol,
                    $start,
                    $end,
                    (int)$step
                );

                // Normalisation simple, alignée à ton Kline::toArray()
                $klinesByTf[$tf] = array_map(static function (Kline $k) {
                    return [
                        'id'        => $k->getId(),
                        'timestamp' => $k->getTimestamp()->getTimestamp(),
                        'open'      => $k->getOpen(),
                        'close'     => $k->getClose(),
                        'high'      => $k->getHigh(),
                        'low'       => $k->getLow(),
                        'volume'    => $k->getVolume(),
                        'step'      => $k->getStep(),
                        'contract'  => $k->getContract()->getSymbol(),
                    ];
                }, $klines);
            }

            $out[] = [
                'id'               => $p->getId(),
                'contract_symbol'  => $symbol,
                'current_timeframe'=> $p->getCurrentTimeframe(),
                'last_attempt_at'  => $p->getLastAttemptAt()?->format('Y-m-d H:i:s'),
                'status'           => $p->getStatus(),
                'updated_at'       => $p->getUpdatedAt()->format('Y-m-d H:i:s'),
                'signals'          => $signals,
                'from_timestamp'   => $fromTs !== null ? (new \DateTimeImmutable("@$fromTs"))->format('Y-m-d H:i:s') : null,
                'to_timestamp'     => $toTs   !== null ? (new \DateTimeImmutable("@$toTs"))->format('Y-m-d H:i:s') : null,
                'klines'           => $klinesByTf,
            ];
        }

        return $this->json($out);
    }
}
