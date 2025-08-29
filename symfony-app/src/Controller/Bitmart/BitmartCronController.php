<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Repository\ContractPipelineRepository;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

final class BitmartCronController extends AbstractController
{
    private const BASE_URL     = 'http://nginx';
    private const CALLBACK     = 'api/callback/bitmart/get-kline';
    private const LIMIT_KLINES = 100;

    #[Route('/api/bitmart/cron/refresh-4h', name: 'bitmart_cron_refresh_4h', methods: ['POST'])]
    public function refresh4h(
        ContractRepository $contracts,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        ContractPipelineService $pipelineService,
        LoggerInterface $logger,
    ): JsonResponse {
        /** @var Contract[] $all */
        $all = $contracts->findAll();

        $ref = new WorkflowRef('default', 'rate-limited-echo', 'ApiRateLimiterClient', 'api_rate_limiter_queue');

        $sent = 0;
        $step = $this->stepFor('4h');

        foreach ($all as $contract) {
            // Seed/assure présence en pipeline 4h (idempotent)
            $pipelineService->ensureSeeded4h($contract);

            $last = $klines->findOneBy(['contract' => $contract, 'step' => $step], ['timestamp' => 'DESC']);
            $sinceTs = $last ? $last->getTimestamp()->getTimestamp() : null;

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $contract->getSymbol(),
                timeframe: '4h',
                limit: self::LIMIT_KLINES,
                sinceTs: $sinceTs
            );
            $sent++;
        }

        $logger->info('[BitmartCron] Signals envoyés', ['tf' => '4h', 'count' => $sent]);

        return $this->json(['status' => 'ok', 'timeframe' => '4h', 'sent_signals' => $sent]);
    }

    #[Route('/api/bitmart/cron/refresh-1h', name: 'bitmart_cron_refresh_1h', methods: ['POST'])]
    public function refresh1h(
        ContractPipelineRepository $pipelineRepo,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
    ): JsonResponse {
        $sent = $this->dispatchForEligible('1h', $pipelineRepo, $klines, $orchestrator);
        $logger->info('[BitmartCron] Signals envoyés', ['tf' => '1h', 'count' => $sent]);
        return $this->json(['status' => 'ok', 'timeframe' => '1h', 'sent_signals' => $sent]);
    }

    #[Route('/api/bitmart/cron/refresh-15m', name: 'bitmart_cron_refresh_15m', methods: ['POST'])]
    public function refresh15m(
        ContractPipelineRepository $pipelineRepo,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
    ): JsonResponse {
        $sent = $this->dispatchForEligible('15m', $pipelineRepo, $klines, $orchestrator);
        $logger->info('[BitmartCron] Signals envoyés', ['tf' => '15m', 'count' => $sent]);
        return $this->json(['status' => 'ok', 'timeframe' => '15m', 'sent_signals' => $sent]);
    }

    #[Route('/api/bitmart/cron/refresh-5m', name: 'bitmart_cron_refresh_5m', methods: ['POST'])]
    public function refresh5m(
        ContractPipelineRepository $pipelineRepo,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
    ): JsonResponse {
        $sent = $this->dispatchForEligible('5m', $pipelineRepo, $klines, $orchestrator);
        $logger->info('[BitmartCron] Signals envoyés', ['tf' => '5m', 'count' => $sent]);
        return $this->json(['status' => 'ok', 'timeframe' => '5m', 'sent_signals' => $sent]);
    }

    #[Route('/api/bitmart/cron/refresh-1m', name: 'bitmart_cron_refresh_1m', methods: ['POST'])]
    public function refresh1m(
        ContractPipelineRepository $pipelineRepo,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
    ): JsonResponse {
        $sent = $this->dispatchForEligible('1m', $pipelineRepo, $klines, $orchestrator);
        $logger->info('[BitmartCron] Signals envoyés', ['tf' => '1m', 'count' => $sent]);
        return $this->json(['status' => 'ok', 'timeframe' => '1m', 'sent_signals' => $sent]);
    }

    // ================== Helpers ==================

    private function stepFor(string $tf): int
    {
        return match ($tf) {
            '4h' => 4 * 3600,
            '1h' => 3600,
            '15m' => 15 * 60,
            '5m' => 5 * 60,
            '1m' => 60,
            default => throw new \InvalidArgumentException("Timeframe inconnu: $tf"),
        };
    }

    private function dispatchForEligible(
        string $timeframe,
        ContractPipelineRepository $pipelineRepo,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator
    ): int {
        $ref  = new WorkflowRef('default', 'rate-limited-echo', 'ApiRateLimiterClient', 'api_rate_limiter_queue');
        $step = $this->stepFor($timeframe);
        $sent = 0;

        $eligible = $pipelineRepo->findEligibleFor($timeframe);

        foreach ($eligible as $pipe) {
            $contract = $pipe->getContract();

            $last = $klines->findOneBy(['contract' => $contract, 'step' => $step], ['timestamp' => 'DESC']);
            $sinceTs = $last ? $last->getTimestamp()->getTimestamp() : null;

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $contract->getSymbol(),
                timeframe: $timeframe,
                limit: self::LIMIT_KLINES,
                sinceTs: $sinceTs
            );
            $sent++;
        }

        return $sent;
    }
}
