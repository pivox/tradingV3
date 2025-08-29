<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Entity\Position;
use App\Repository\ContractPipelineRepository;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Indicator\IndicatorValidatorClient;
use App\Service\Persister\KlinePersister;
use App\Service\Pipeline\ContractPipelineService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Callback “get-kline” appelé par le workflow rate-limiter.
 * Étapes :
 *  - Parse l’enveloppe
 *  - Fetch des klines Bitmart (via BitmartFetcher) sur la fenêtre demandée
 *  - Persistence des klines
 *  - Appel à l’API indicateur /validate (avec klines)
 *  - Application de la décision (promotion/rétrogradation)
 *  - Si timeframe=1m et valid=true -> ouverture Position(100 USDT)
 */
final class BitmartKlinesCallbackController extends AbstractController
{
    public function __construct(
        private readonly IndicatorValidatorClient $indicatorClient
    ) {}

    #[Route('/api/callback/bitmart/get-kline', name: 'bitmart_klines_callback', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntityManagerInterface $em,
        ContractPipelineRepository $pipelineRepo,
        ContractPipelineService $pipelineService,
        BitmartFetcher $bitmartFetcher,
        KlinePersister $persister,
        LoggerInterface $logger,
    ): JsonResponse {

        // 1) Lire l’enveloppe envoyée par le workflow
        $envelope = json_decode($request->getContent(), true) ?? [];
        $payload  = $envelope['payload'] ?? [];

        $symbol    = (string) ($payload['contract']  ?? '');
        $timeframe = (string) ($payload['timeframe'] ?? '4h');
        $limit     = (int)    ($payload['limit']     ?? 100);
        $sinceTs   = isset($payload['since_ts']) ? (int) $payload['since_ts'] : null;

        if ($symbol === '') {
            return $this->json(['status' => 'error', 'message' => 'Missing contract symbol'], 400);
        }

        // 2) Récupérer le Contract
        /** @var Contract|null $contract */
        $contract = $em->getRepository(Contract::class)->find($symbol);
        if (!$contract) {
            return $this->json(['status' => 'error', 'message' => 'Contract not found: '.$symbol], 404);
        }

        // 3) Timeframe mapping
        $tfToMinutes = [
            '1m'=>1,'3m'=>3,'5m'=>5,'15m'=>15,'30m'=>30,
            '1h'=>60,'2h'=>120,'4h'=>240,'6h'=>360,'12h'=>720,
            '1d'=>1440,'3d'=>4320,'1w'=>10080,
        ];
        $stepMinutes = $tfToMinutes[$timeframe] ?? 240;
        $stepSeconds = $stepMinutes * 60;

        // 4) Fenêtre temporelle
        $now   = new \DateTimeImmutable();
        $start = $sinceTs
            ? (new \DateTimeImmutable())->setTimestamp($sinceTs)
            : $now->sub(new \DateInterval('PT'.($limit * $stepMinutes).'M'));
        $end   = $now;

        // 5) Fetch klines via BitmartFetcher (amont rate-limiter)
        $dtos = $bitmartFetcher->fetchKlines($symbol, $start, $end, $stepMinutes);

        // 6) Persistance klines
        $persisted = $persister->persistMany($contract, $dtos, $stepSeconds);

        // 7) Construire la payload pour l’API indicateur à partir des DTOs
        //    On garde l’ordre chronologique ascendant.
        $klinesPayload = array_map(function ($dto) {
            // On suppose que le DTO expose : getTimestamp(), getOpen(), getHigh(), getLow(), getClose(), getVolume()
            return [
                'timestamp' => (int)    $dto->getTimestamp()->getTimestamp(),
                'open'      => (float)  $dto->getOpen(),
                'high'      => (float)  $dto->getHigh(),
                'low'       => (float)  $dto->getLow(),
                'close'     => (float)  $dto->getClose(),
                'volume'    => (float)  $dto->getVolume(),
            ];
        }, $dtos);

        // 8) Appel API indicateur /validate via le client dédié
        $decision = $this->indicatorClient->validate($symbol, $timeframe, $klinesPayload); // ex: ['valid'=>bool, 'side'=>'LONG'|'SHORT', ...]

        // 9) Appliquer la décision au pipeline (si ligne trouvée)
        $pipe = $pipelineRepo->findOneBy(['contract' => $contract]);
        if ($pipe) {
            $pipelineService->markAttempt($pipe);
            if (is_array($decision) && array_key_exists('valid', $decision)) {
                $pipelineService->applyDecision($pipe, $timeframe, $decision);
            }
        }

        // 10) Si timeframe = 1m et valid = true → ouvrir une position 100 USDT
        if (is_array($decision) && !empty($decision['valid']) && $timeframe === '1m') {
            $side = strtoupper((string)($decision['side'] ?? Position::SIDE_LONG));
            if (!in_array($side, [Position::SIDE_LONG, Position::SIDE_SHORT], true)) {
                $side = Position::SIDE_LONG;
            }

            $pos = (new Position())
                ->setContract($contract)
                ->setExchange('bitmart')
                ->setSide($side)
                ->setStatus(Position::STATUS_OPEN)
                ->setAmountUsdt('100') // 100 USDT
                ->setOpenedAt(new \DateTimeImmutable())
                ->setMeta($decision);

            $em->persist($pos);
            $em->flush();

            $logger->info('[Position] Opened 100 USDT', [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'side' => $side, 'position_id' => $pos->getId(),
            ]);
        }

        $logger->info('✅ Klines persisted + decision processed', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'step_minutes' => $stepMinutes,
            'persisted' => $persisted,
            'decision' => $decision,
        ]);

        return $this->json([
            'status'    => 'ok',
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'persisted' => $persisted,
            'decision'  => $decision,
        ]);
    }
}
