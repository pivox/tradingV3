<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Repository\{ContractRepository, RuntimeGuardRepository};
use App\Service\Pipeline\MtfDecisionService;
use App\Service\Pipeline\MtfEligibilityFinder;
use App\Service\Pipeline\MtfSignalStore;
use App\Service\Pipeline\PipelineMeta;
use App\Service\Pipeline\SlotService;
use App\Service\Trading\AssetsDetails;
use App\Service\Config\TradingParameters;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request, JsonResponse};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class BitmartCronController extends AbstractController
{
    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly ContractRepository $contractRepository,
        private readonly RuntimeGuardRepository $runtimeGuardRepository,
        private readonly AssetsDetails $assetsDetails,
        private readonly MtfEligibilityFinder $eligibilityFinder,
        private readonly MtfSignalStore $signalStore,
        private readonly SlotService $slotService,
        private readonly MtfDecisionService $decisionService,
        #[Autowire(service: 'monolog.logger.pipeline_exec')]
        private readonly LoggerInterface $executionLogger,
    ) {}

    private const BASE_URL = 'http://nginx';
    private const CALLBACK = 'api/callback/bitmart/get-kline';
    private const LIMIT_KLINES = 260;

    #[Route('/api/cron/bitmart/refresh-{tf}', name: 'bitmart_cron_refresh', methods: ['POST'])]
    public function refresh(
        string $tf,
        Request $request,
        TradingParameters $tradingParams
    ): JsonResponse {
        if ($guard = $this->guard()) {
            return $guard;
        }

        $cascadeOrder = ['4h', '1h', '15m', '5m', '1m'];
        $force = $request->query->getBoolean('force', false);
        $this->executionLogger->info('[pipeline-cron] refresh invoked', [
            'tf' => $tf,
            'force' => $force,
            'query' => $request->query->all(),
        ]);
        if (!in_array($tf, $cascadeOrder, true)) {
            return $this->json([
                'status' => 'error',
                'message' => 'Timeframe invalide',
                'allowed' => $cascadeOrder,
            ], 400);
        }

        if ($tf === '1m') {
            try {
                if (!$this->assetsDetails->hasEnoughtMoneyInUsdt('USDT', 50)) {
                    $this->executionLogger->warning('[pipeline-cron] skipped insufficient balance', ['tf' => $tf]);
                    return $this->json([
                        'status' => 'skipped_assets_insufficient',
                        'timeframe' => $tf,
                    ], 200);
                }
            } catch (\Throwable $e) {
                $this->executionLogger->error('[pipeline-cron] exception on assets detail', [
                    'tf' => $tf,
                    'error' => $e->getMessage(),
                ]);
                return $this->json([
                    'status' => 'skipped_assets_exception',
                    'timeframe' => $tf,
                    'error' => $e->getMessage()
                ], 200);
            }
        }

        $contracts = [];
        if ($request->query->has('symbol')) {
            $singleSymbol = strtoupper((string)$request->query->get('symbol'));
            $contract = $this->contractRepository->find($singleSymbol);
            if ($contract) {
                $contracts = [$contract];
            }
        } else {
            $eligibleSymbols = $this->eligibilityFinder->eligibleSymbols($tf, 400, !$force, $force, $force);
            $this->executionLogger->debug('[pipeline-cron] eligible symbols resolved', [
                'tf' => $tf,
                'force' => $force,
                'eligible_count' => count($eligibleSymbols),
                'eligible_symbols' => $eligibleSymbols,
            ]);
            if ($tf === '4h' && $eligibleSymbols === []) {
                $eligibleSymbols = $this->contractRepository->allActiveSymbolNames();
            }
            $contracts = $this->loadContracts($eligibleSymbols);
        }

        if (!$force) {
            $before = count($contracts);
            $contracts = $this->removeFreshSymbols($contracts, $tf);
            $this->executionLogger->debug('[pipeline-cron] filtered fresh contracts', [
                'tf' => $tf,
                'before' => $before,
                'after' => count($contracts),
            ]);
        }

        if ($contracts === []) {
            $this->executionLogger->info('[pipeline-cron] no dispatch candidates', ['tf' => $tf, 'force' => $force]);
            return $this->json([
                'status' => 'ok',
                'timeframe' => $tf,
                'sent' => 0,
                'requests_total' => 0,
                'batch_id' => null,
                'list' => [],
            ]);
        }

        $symbolNames = array_map(static fn(Contract $contract) => $contract->getSymbol(), $contracts);
        $batchId     = sprintf('cron-%s-%s', strtolower($tf), (new \DateTimeImmutable())->format('YmdHisv'));
        $workflowRef = new WorkflowRef('api-rate-limiter-workflow', 'ApiRateLimiterClient', 'api_rate_limiter_queue');

        $this->bitmartOrchestrator->reset();
        $this->bitmartOrchestrator->setWorkflowRef($workflowRef);
        $count = 0;

        $listCascades = $this->getListCascades($symbolNames, $tf);
        $this->executionLogger->debug('[pipeline-cron] cascades computed', [
            'tf' => $tf,
            'cascades' => $listCascades,
        ]);
        foreach ($listCascades as $parentTf => $symbolList) {
            foreach ($symbolList as $symbol) {
                $meta = $this->buildRequestMeta(
                    symbol: $symbol,
                    rootTf: $tf,
                    parentTf: $parentTf,
                    batchId: $batchId,
                    force: $force,
                    includePipelineSkip: true // cascade parent \=\> ne pas ouvrir/altérer
                );
                $this->bitmartOrchestrator->requestGetKlines(
                    $workflowRef,
                    baseUrl: self::BASE_URL,
                    callback: self::CALLBACK,
                    contract: $symbol,
                    timeframe: $parentTf,
                    limit: self::LIMIT_KLINES,
                    note: "cron $tf (cascade $parentTf)",
                    batchId: $batchId,
                    meta: $meta
                );
                $count++;
            }
        }

        foreach ($contracts as $contract) {
            $symbol = $contract->getSymbol();
            $includeSkip = $this->shouldSendMeta($symbol, $tf);
            $meta = $this->buildRequestMeta(
                symbol: $symbol,
                rootTf: $tf,
                parentTf: null,
                batchId: $batchId,
                force: $force,
                includePipelineSkip: $includeSkip
            );
            $this->bitmartOrchestrator->requestGetKlines(
                $workflowRef,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $symbol,
                timeframe: $tf,
                limit: self::LIMIT_KLINES,
                note: "cron $tf",
                batchId: $batchId,
                meta: $meta
            );
            $count++;
        }

        $this->bitmartOrchestrator->go();
        $this->executionLogger->info('[pipeline-cron] dispatched batch', [
            'tf' => $tf,
            'force' => $force,
            'symbols' => $symbolNames,
            'cascade_requests' => array_map('count', $listCascades),
            'contracts' => count($contracts),
            'requests_total' => $count,
            'batch_id' => $batchId,
        ]);

        return $this->json([
            'status' => 'ok',
            'timeframe' => $tf,
            'sent' => count($contracts),
            'requests_total' => $count,
            'batch_id' => $batchId,
            'list' => $symbolNames,
        ]);
    }

    private function guard(): ?JsonResponse {
        if (!$this->runtimeGuardRepository->isPaused()) {
            return null;
        }
        $this->executionLogger->info('[pipeline-cron] runtime guard paused');
        return new JsonResponse(['status' => 'paused'], 200);
    }

    private function removeFreshSymbols(array $contracts, string $tf): array
    {
        $intervals = [
            '4h' => new \DateInterval('PT4H'),
            '1h' => new \DateInterval('PT30M'),
            '15m' => new \DateInterval('PT10M'),
            '5m' => new \DateInterval('PT3M'),
            '1m' => new \DateInterval('PT30S'),
        ];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cutoffInterval = $intervals[$tf] ?? new \DateInterval('PT5M');
        $threshold = $now->sub($cutoffInterval);
        $slot = $this->slotService->currentSlot($tf, $now);

        return array_values(array_filter($contracts, function (Contract $contract) use ($tf, $threshold, $slot) {
            $signals = $this->signalStore->fetchLatestSignals($contract->getSymbol());
            $latest = $signals[$tf]['slot_start'] ?? null;
            if ($latest === null) {
                return true;
            }
            if ($tf === '4h') {
                return $latest < $slot;
            }
            return $latest <= $threshold;
        }));
    }

    /**
     * @return array<string,string[]>
     */
    private function getListCascades(array $symbolNames, string $currentTf): array
    {
        return $this->eligibilityFinder->staleParentsForSymbols($symbolNames, $currentTf);
    }

    /**
     * @param string[] $symbols
     * @return Contract[]
     */
    private function loadContracts(array $symbols): array
    {
        $contracts = [];
        foreach ($symbols as $symbol) {
            $contract = $this->contractRepository->find($symbol);
            if ($contract) {
                $contracts[] = $contract;
            }
        }
        return $contracts;
    }

    private function shouldSendMeta(string $symbol, string $tf): bool
    {
        if (!in_array($tf, ['5m', '1m'], true)) {
            return false;
        }
        $signals = $this->signalStore->fetchLatestSignals($symbol);
        if ($tf === '5m') {
            $decision = $this->decisionService->decide('15m', $signals);
            return !$decision['is_valid'];
        }
        $decision5m = $this->decisionService->decide('5m', $signals);
        $decision15m = $this->decisionService->decide('15m', $signals);
        return !$decision5m['is_valid'] || !$decision15m['is_valid'];
    }

    /**
     * Construit le meta standard pour chaque requête envoyée au callback.
     */
    private function buildRequestMeta(
        string $symbol,
        string $rootTf,
        ?string $parentTf,
        string $batchId,
        bool $force,
        bool $includePipelineSkip
    ): array {
        $meta = [
            'batch_id'   => $batchId,
            'request_id' => sprintf('%s|%s|%s|%d', $symbol, $rootTf, $parentTf ?? $rootTf, (int)(microtime(true)*1000)),
            'root_tf'    => $rootTf,
            'parent_tf'  => $parentTf,
            'force'      => $force,
            'source'     => 'cron',
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
        if ($includePipelineSkip) {
            $meta['pipeline'] = PipelineMeta::DONT_INC_DEC_DEL;
        }
        return $meta;
    }
}
