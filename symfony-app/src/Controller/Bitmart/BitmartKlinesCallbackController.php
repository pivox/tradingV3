<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class BitmartKlinesCallbackController extends AbstractController
{
    #[Route('/api/callback/bitmart/get-kline', name: 'bitmart_klines_callback', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntityManagerInterface $em,
        BitmartFetcher $bitmartFetcher,
        KlinePersister $persister,
        LoggerInterface $logger,
    ): JsonResponse {
        // 1) Lire l’enveloppe
        $envelope = json_decode($request->getContent(), true) ?? [];
        $payload  = $envelope['payload'] ?? [];

        $symbol    = (string) ($payload['contract']  ?? '');
        $timeframe = (string) ($payload['timeframe'] ?? '4h');
        $limit     = (int)    ($payload['limit']     ?? 100);
        $sinceTs   = isset($payload['since_ts']) ? (int) $payload['since_ts'] : null;

        if ($symbol === '') {
            return $this->json(['status' => 'error', 'message' => 'Missing contract symbol'], 400);
        }

        // 2) Trouver le Contract
        /** @var Contract|null $contract */
        $contract = $em->getRepository(Contract::class)->find($symbol);
        if (!$contract) {
            return $this->json(['status' => 'error', 'message' => 'Contract not found: '.$symbol], 404);
        }

        // 3) Mapping timeframe -> step minutes (BitMart) et -> step secondes (entité)
        $tfToMinutes = [
            '1m'=>1,'3m'=>3,'5m'=>5,'15m'=>15,'30m'=>30,
            '1h'=>60,'2h'=>120,'4h'=>240,'6h'=>360,'12h'=>720,
            '1d'=>1440,'3d'=>4320,'1w'=>10080,
        ];
        $stepMinutes = $tfToMinutes[$timeframe] ?? 240; // défaut 4h
        $stepSeconds = $stepMinutes * 60;               // pour la colonne Kline::step (en secondes) :contentReference[oaicite:2]{index=2}

        // 4) Fenêtre temporelle
        $now   = new \DateTimeImmutable();
        $start = $sinceTs
            ? (new \DateTimeImmutable())->setTimestamp($sinceTs)
            : $now->sub(new \DateInterval('PT'.($limit * $stepMinutes).'M'));
        $end   = $now;
        // 5) Fetch via BitmartFetcher (rate-limiter amont) — step attendu en MINUTES
        $dtos = $bitmartFetcher->fetchKlines($symbol, $start, $end, $stepMinutes);

        // 6) Persistance (DTO -> entité Kline), step stocké en SECONDES
        $persisted = $persister->persistMany($contract, $dtos, $stepSeconds);

        $logger->info('✅ Klines persisted', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'step_minutes' => $stepMinutes,
            'persisted' => $persisted,
        ]);

        return $this->json([
            'status' => 'ok',
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'persisted' => $persisted,
        ]);
    }
}
