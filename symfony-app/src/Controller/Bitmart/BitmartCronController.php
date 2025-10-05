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
        $cutoff = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes);
        $start = $cutoff->modify('-' . (self::LIMIT_KLINES - 1) * $tfMinutes . ' minutes');

        $symbols = array_values(array_unique($this->getSymbols($tf, $request, $contractPipelineRepository, $tradingParams)));
        if (!$symbols) {
            $logger->warning("Bitmart cron $tf: aucun symbole sélectionné", ['tf' => $tf]);
            return $this->json([
                'status' => 'empty',
                'timeframe' => $tf,
                'sent' => 0,
                'list' => [],
            ]);
        }

        $batchId = sprintf('cron-%s-%s', strtolower($tf), (new \DateTimeImmutable())->format('YmdHisv'));
        $workflowRef = new WorkflowRef('api-rate-limiter-workflow', 'ApiRateLimiterClient', 'api_rate_limiter_queue');
        $this->bitmartOrchestrator->reset();
        $this->bitmartOrchestrator->setWorkflowRef($workflowRef);
        foreach ($symbols as $symbol) {
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

        $logger->info("Bitmart cron $tf dispatched", [
            'tf' => $tf,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $cutoff->format('Y-m-d H:i:s'),
            'count' => count($symbols),
            'list' => $symbols,
            'batch_id' => $batchId,
        ]);

        return $this->json([
            'status' => 'ok',
            'timeframe' => $tf,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $cutoff->format('Y-m-d H:i:s'),
            'sent' => count($symbols),
            'list' => $symbols,
            'batch_id' => $batchId,
        ]);
    }

    private function getSymbols(
        string $tf,
        Request $request,
        ContractPipelineRepository $contractPipelineRepository,
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
        $symbols = $contractPipelineRepository->getAllSymbolsWithActiveTimeframe($tf);
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
