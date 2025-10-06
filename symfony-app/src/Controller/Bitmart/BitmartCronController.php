<?php

namespace App\Controller\Bitmart;

use App\Repository\{ContractRepository, ContractPipelineRepository, RuntimeGuardRepository};
use App\Service\Config\TradingParameters;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use App\Util\TimeframeHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\{Request, JsonResponse};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class BitmartCronController extends AbstractController
{
    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly ContractRepository $contractRepository,
        private readonly RuntimeGuardRepository $runtimeGuardRepository,
        private readonly ContractPipelineRepository $contractPipelineRepository
    ) {}

    private const BASE_URL = 'http://nginx';
    private const CALLBACK = 'api/callback/bitmart/get-kline';
    private const LIMIT_KLINES = 260;

    #[Route('/api/cron/bitmart/refresh-{tf}', name: 'bitmart_cron_refresh', methods: ['POST'])]
    public function refresh(
        string $tf,
        ContractPipelineRepository $contractPipelineRepository,
        LoggerInterface $logger,
        Request $request,
        TradingParameters $tradingParams
    ): JsonResponse {
        if ($guard = $this->guard()) {
            return $guard;
        }

        $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($tf);
        $cutoff    = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes);
        $start     = $cutoff->modify('-' . (self::LIMIT_KLINES - 1) * $tfMinutes . ' minutes');

        $symbols = array_values(array_unique($this->getSymbols($tf, $request, $tradingParams)));
        if (!$symbols) {
            $logger->warning("Bitmart cron $tf: aucun symbole sélectionné", ['tf' => $tf]);
            return $this->json(['status' => 'empty', 'timeframe' => $tf, 'sent' => 0, 'list' => []]);
        }

        $batchId     = sprintf('cron-%s-%s', strtolower($tf), (new \DateTimeImmutable())->format('YmdHisv'));
        $workflowRef = new WorkflowRef('api-rate-limiter-workflow', 'ApiRateLimiterClient', 'api_rate_limiter_queue');

        $this->bitmartOrchestrator->reset();
        $this->bitmartOrchestrator->setWorkflowRef($workflowRef);

        // Cascade définie du plus grand au plus petit (ordre logique de dépendance)
        $cascadeOrder = ['4h', '1h', '15m', '5m', '1m'];

        // Seuils d’obsolescence pour chaque TF
        $staleMap = ['4h' => 240, '1h' => 60, '15m' => 15, '5m' => 5, '1m' => 1];

        // Trouver les TF supérieurs à celui passé
        $tfIndex = array_search($tf, $cascadeOrder, true);
        $tfSuperiors = array_slice($cascadeOrder, 0, $tfIndex); // ceux avant dans la liste

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // --- Boucle symboles ---
        foreach ($symbols as $symbol) {
            // Charger le pipeline pour vérifier les signaux existants
            $pipe = $contractPipelineRepository->createQueryBuilder('p')
                ->innerJoin('p.contract', 'c')
                ->where('c.symbol = :sym')
                ->setParameter('sym', $symbol)
                ->orderBy('p.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();

            $signals = ($pipe && $pipe->getSignals()) ?? [];

            // 1️⃣ D’abord lancer les TF supérieurs si périmés
            foreach ($tfSuperiors as $tfSup) {
                $staleMins = $staleMap[$tfSup];
                $lastDate  = isset($signals[$tfSup]['date'])
                    ? new \DateTimeImmutable($signals[$tfSup]['date'], new \DateTimeZone('UTC'))
                    : null;

                $threshold = $nowUtc->modify(sprintf('-%d minutes', $staleMins));
                $isStale   = !$lastDate || $lastDate < $threshold;

                if ($isStale) {
                    $logger->info("Pré-dispatch cascade TF supérieur", [
                        'symbol' => $symbol, 'tf' => $tfSup, 'from' => $tf, 'last' => $lastDate?->format('Y-m-d H:i:s'),
                    ]);

                    $mX      = TimeframeHelper::parseTimeframeToMinutes($tfSup);
                    $cutoffX = TimeframeHelper::getAlignedOpenByMinutes($mX);
                    $startX  = $cutoffX->modify('-' . (self::LIMIT_KLINES - 1) * $mX . ' minutes');

                    $this->bitmartOrchestrator->requestGetKlines(
                        $workflowRef,
                        baseUrl: self::BASE_URL,
                        callback: self::CALLBACK,
                        contract: $symbol,
                        timeframe: $tfSup,
                        limit: self::LIMIT_KLINES,
                        start: $startX,
                        end: $cutoffX,
                        note: "cascade pré-$tf",
                        batchId: $batchId
                    );
                }
            }

            // 2️⃣ Ensuite lancer le TF demandé lui-même
            $this->bitmartOrchestrator->requestGetKlines(
                $workflowRef,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $symbol,
                timeframe: $tf,
                limit: self::LIMIT_KLINES,
                start: $start,
                end: $cutoff,
                note: "cron $tf",
                batchId: $batchId
            );
        }

        $this->bitmartOrchestrator->go();

        $logger->info("Bitmart cron $tf dispatched avec cascade ascendante", [
            'tf'    => $tf,
            'count' => count($symbols),
            'batch' => $batchId,
            'list'  => $symbols,
        ]);

        return $this->json([
            'status'    => 'ok',
            'timeframe' => $tf,
            'sent'      => count($symbols),
            'batch_id'  => $batchId,
            'list'      => $symbols,
        ]);
    }



    private function getSymbols(
        string $tf,
        Request $request,
        TradingParameters $tradingParams
    ): array {
        $cfg = $tradingParams->getConfig();
        $allowedQuotes = array_map('strtoupper', $cfg['symbols']['allowed_quotes'] ?? []);
        $blacklist = array_map('strtoupper', $cfg['symbols']['blacklist'] ?? []);

        if ($request->query->has('symbol')) {
            $symbol = (string) $request->query->get('symbol');
            $contract = $this->contractRepository->find($symbol);
            return ($contract && $this->filterContracts($contract, $allowedQuotes, $blacklist))
                ? [$symbol]
                : [];
        }

        $contracts = $this->contractRepository->allActiveSymbols();
        if ($tf == '4h') {
            return array_map(fn($c) => $c->getSymbol(), $contracts);
        }
        $symbols = $this->contractPipelineRepository->getAllSymbolsWithActiveTimeframe($tf);
        return array_values(array_filter($symbols, function (string $symbol) use ($allowedQuotes, $blacklist): bool {
            $contract = $this->contractRepository->find($symbol);
            if (!$contract) {
                return false;
            }
            return $this->filterContracts($contract, $allowedQuotes, $blacklist);
        }));
    }

    private function filterContracts($contract, array $allowedQuotes, array $blacklist): bool {
        $symbol = strtoupper($contract->getSymbol());
        $quote = strtoupper($contract->getQuoteCurrency());
        return !in_array($symbol, $blacklist, true) &&
            (empty($allowedQuotes) || in_array($quote, $allowedQuotes, true));
    }

    private function guard(): ?JsonResponse {
        return $this->runtimeGuardRepository->isPaused()
            ? new JsonResponse(['status' => 'paused'], 200)
            : null;
    }
}
