<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Repository\ContractPipelineRepository;
use App\Service\ContractSignalWriter;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Signals\Timeframe\Signal1hService;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

final class BitmartCronController extends AbstractController
{
    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
    )
    {
    }

    private const BASE_URL     = 'http://nginx';
    private const CALLBACK     = 'api/callback/bitmart/get-kline';
    private const LIMIT_KLINES = 221;

    #[Route('/api/cron/bitmart/refresh-4h', name: 'bitmart_cron_refresh_4h', methods: ['POST'])]
    public function refresh4h(
        ContractRepository $contracts,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        ContractPipelineService $pipelineService,
        LoggerInterface $logger,
    ): JsonResponse {
        $contracts = $this->contractRepository->findBy(['exchange' => 'bitmart']);
        $stepMinutes = 240;
        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $minSince = $now->sub(new \DateInterval('PT'.((self::LIMIT_KLINES - 1) * $stepMinutes).'M'));

        foreach ($contracts as $contract) {
            $sinceTs = $this->klineRepository->findLastKline($contract, $stepMinutes);
//            if ($sinceTs) {
//                $sinceTs = $sinceTs->setTimezone(new \DateTimeZone('UTC'));
//                if ($sinceTs > $minSince) {
//                    $sinceTs = $minSince;
//                }
//            } else {
//                $sinceTs = $minSince;
//            }
            $this->bitmartOrchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $contract->getSymbol(),
                timeframe: '4h',
                limit: self::LIMIT_KLINES,
                sinceTs: $sinceTs?->getTimestamp(),
                note: 'cron 4h',
            );
        }
        $logger->info('Bitmart cron 4h executed');
        return new JsonResponse(['status' => 'ok', 'cron' => '4h']);
    }

    #[Route('/api/cron/bitmart/refresh-1h', name: 'bitmart_cron_refresh_1h', methods: ['GET','POST'])]
    public function refresh1h(
        ContractPipelineRepository $pipelineRepo,
        ContractPipelineService $pipelineService,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
        ContractPipelineRepository $contractPipelineRepository,
    ): JsonResponse {
        $contracts = $contractPipelineRepository->getAllSymbolsWithActive1h();
        // $contracts = array_map(fn($contract) => $contract->getSymbol(), $this->contractRepository->findBy(['exchange' => 'bitmart']));
        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        $step = $this->stepFor('1h');

        foreach ($contracts as $contract) {
            // Seed/assure présence en pipeline 1h (idempotent)

            $last = $klines->findOneBy(['contract' => $contract, 'step' => $step], ['timestamp' => 'DESC']);
            $sinceTs = $last ? $last->getTimestamp()->getTimestamp() : null;

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $contract,
                timeframe: '1h',
                limit: self::LIMIT_KLINES,
                sinceTs: $sinceTs
            );
            $sent++;
        }

        $logger->info('[BitmartCron] Signals envoyés', ['tf' => '1h', 'count' => $sent]);

        return $this->json(['status' => 'ok', 'timeframe' => '1h', 'sent_signals' => $sent]);
    }

    #[Route('/api/cron/bitmart/refresh-15m', name: 'bitmart_cron_refresh_15m', methods: ['POST'])]
    public function refresh15m(
        ContractPipelineRepository $contractPipelineRepository,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
    ): JsonResponse {
       $contracts = $contractPipelineRepository->getAllSymbolsWithActive15m();
       // $contracts = array_map(fn($contract) => $contract->getSymbol(), $this->contractRepository->findBy(['exchange' => 'bitmart']));

        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        $step = $this->stepFor('15m');

        foreach ($contracts as $contract) {
            // Seed/assure présence en pipeline 15m (idempotent)

            $last = $klines->findOneBy(['contract' => $contract, 'step' => $step], ['timestamp' => 'DESC']);
            $sinceTs = $last ? $last->getTimestamp()->getTimestamp() : null;

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $contract,
                timeframe: '5m',
                limit: self::LIMIT_KLINES,
                sinceTs: $sinceTs
            );
            $sent++;
        }

        $logger->info('[BitmartCron] Signals envoyés', ['tf' => '15m', 'count' => $sent]);

        return $this->json(['status' => 'ok', 'timeframe' => '15m', 'sent_signals' => $sent]);
    }

    #[Route('/api/cron/bitmart/refresh-5m', name: 'bitmart_cron_refresh_5m', methods: ['POST'])]
    public function refresh5m(
        ContractPipelineRepository $contractPipelineRepository,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
    ): JsonResponse {
        $contracts = $contractPipelineRepository->getAllSymbolsWithActive5m();
        //$contracts = array_map(fn($contract) => $contract->getSymbol(), $this->contractRepository->findBy(['exchange' => 'bitmart']));

        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        $step = $this->stepFor('5m');

        foreach ($contracts as $contract) {
            // Seed/assure présence en pipeline 5m (idempotent)

            $last = $klines->findOneBy(['contract' => $contract, 'step' => $step], ['timestamp' => 'DESC']);
            $sinceTs = $last ? $last->getTimestamp()->getTimestamp() : null;

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $contract,
                timeframe: '5m',
                limit: self::LIMIT_KLINES,
                sinceTs: $sinceTs
            );
            $sent++;
        }

        $logger->info('[BitmartCron] Signals envoyés', ['tf' => '5m', 'count' => $sent]);

        return $this->json(['status' => 'ok', 'timeframe' => '5m', 'sent_signals' => $sent]);
    }

    #[Route('/api/cron/bitmart/refresh-1m', name: 'bitmart_cron_refresh_1m', methods: ['POST'])]
    public function refresh1m(
        ContractPipelineRepository $contractPipelineRepository,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
    ): JsonResponse {
        $contracts = $contractPipelineRepository->getAllSymbolsWithActive1m();
        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        $step = $this->stepFor('1m');

        foreach ($contracts as $contract) {
            // Seed/assure présence en pipeline 1m (idempotent)

            $last = $klines->findOneBy(['contract' => $contract, 'step' => $step], ['timestamp' => 'DESC']);
            $sinceTs = $last ? $last->getTimestamp()->getTimestamp() : null;

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $contract,
                timeframe: '1m',
                limit: self::LIMIT_KLINES,
                sinceTs: $sinceTs
            );
            $sent++;
        }

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
        $ref  = new WorkflowRef('default', 'api_rate_limiter_queue', 'ApiRateLimiterClient', 'api_rate_limiter_queue');
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
