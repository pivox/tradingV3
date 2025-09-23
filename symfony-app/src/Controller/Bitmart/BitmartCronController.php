<?php

namespace App\Controller\Bitmart;

use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Repository\ContractPipelineRepository;
use App\Repository\RuntimeGuardRepository;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use App\Util\TimeframeHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

final class BitmartCronController extends AbstractController
{
    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
        private readonly RuntimeGuardRepository $runtimeGuardRepository,
    ) {}

    private const BASE_URL     = 'http://nginx';
    private const CALLBACK     = 'api/callback/bitmart/get-kline';
    private const LIMIT_KLINES = 260;

    #[Route('/api/cron/bitmart/refresh-4h', name: 'bitmart_cron_refresh_4h', methods: ['POST'])]
    public function refresh4h(
        ContractRepository $contracts,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        ContractPipelineService $pipelineService,
        LoggerInterface $logger,
        Request $request
    ): JsonResponse {
        $runGuard = $this->guard();
        if ($runGuard !== null) {
            return $runGuard;
        }
        $tf        = '4h';
        $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($tf);
        $cutoff    = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes); // open de la bougie EN COURS (exclue)
        $end       = $cutoff;
        $endTs     = $end->getTimestamp();

        // backfill max côté cron
        $windowMinutes = (self::LIMIT_KLINES - 1) * $tfMinutes;
        $start = $end->modify("-{$windowMinutes} minutes");
        $startTs = $start->getTimestamp();

        if ($request->query->has('symbol')) {
            $contract = $this->contractRepository->find($request->query->get('symbol'));
            if (!$contract) {
                return new JsonResponse(['status' => 'error', 'message' => 'Contract not found: '.$request->query->get('symbol')], 404);
            }
            $contracts = [$contract];
        } else {
            $contracts = $this->contractRepository->findBy(['exchange' => 'bitmart']);
        }

        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        foreach ($contracts as $contract) {


            $this->bitmartOrchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $contract->getSymbol(),
                timeframe: $tf,
                limit: self::LIMIT_KLINES,
                start: $start,
                end: $end,
                note: 'cron '.$tf
            );
            $sent++;
        }

        $logger->info('Bitmart cron 4h dispatched', [
            'tf'             => $tf,
            'end'         => $end->format('Y-m-d H:i:s'),
            'endTs'       => $endTs,
            'start'  => $start->format('Y-m-d H:i:s'),
            'count'          => $sent,
        ]);

        return $this->json([
            'status'      => 'ok',
            'timeframe'   => $tf,
            'end'         => $end->format('Y-m-d H:i:s'),
            'endTs'       => $endTs,
            'start'  => $start->format('Y-m-d H:i:s'),
            'sent'        => $sent,
        ]);
    }

    #[Route('/api/cron/bitmart/refresh-1h', name: 'bitmart_cron_refresh_1h', methods: ['GET','POST'])]
    public function refresh1h(
        ContractPipelineRepository $contractPipelineRepository,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
        Request $request,
    ): JsonResponse {
        $runGuard = $this->guard();
        if ($runGuard !== null) {
            return $runGuard;
        }
        $symbols = ($request->query->has('all') && $request->query->get('all'))
            ? array_map(fn($c) => $c->getSymbol(), $this->contractRepository->findBy(['exchange' => 'bitmart']))
            : $contractPipelineRepository->getAllSymbolsWithActive1h();

        $tf        = '1h';
        $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($tf); // 60
        $cutoff    = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes);
        $end       = $cutoff;
        $endTs     = $end->getTimestamp();

        $windowMinutes = (self::LIMIT_KLINES - 1) * $tfMinutes;
        $minBackfillTs = $end->modify("-{$windowMinutes} minutes")->getTimestamp();

        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        $step = $this->stepFor($tf); // 3600

        foreach ($symbols as $symbol) {
            $last = $klines->findOneBy(['contract' => $symbol, 'step' => $step], ['timestamp' => 'DESC']);

            $startTs = $minBackfillTs;
            if ($last) {
                $lastTs = $last->getTimestamp()->getTimestamp();
                $nextAlignedTs = $lastTs + $tfMinutes * 60;
                $startTs = max($nextAlignedTs, $minBackfillTs);
            }

            if ($startTs >= $endTs) { continue; }

            $start = (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'));
            $endDT = (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'));

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $symbol,
                timeframe: $tf,
                limit: self::LIMIT_KLINES,
                start: $start,
                end: $endDT,
                note: 'cron '.$tf
            );
            $sent++;
        }

        $logger->info('[BitmartCron] 1h dispatched', [
            'cutoff'        => $end->format(\DateTimeInterface::RFC3339_EXTENDED),
            'cutoffTs'      => $endTs,
            'minBackfillTs' => $minBackfillTs,
            'sent'          => $sent,
        ]);

        return $this->json([
            'status'       => 'ok',
            'timeframe'    => $tf,
            'cutoff_open'  => $end->format('c'),
            'sent_signals' => $sent,
        ]);
    }

    #[Route('/api/cron/bitmart/refresh-15m', name: 'bitmart_cron_refresh_15m', methods: ['POST'])]
    public function refresh15m(
        ContractPipelineRepository $contractPipelineRepository,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
        Request $request,
    ): JsonResponse {
        $runGuard = $this->guard();
        if ($runGuard !== null) {
            return $runGuard;
        }
        $symbols = ($request->query->has('all') && $request->query->get('all'))
            ? array_map(fn($c) => $c->getSymbol(), $this->contractRepository->findBy(['exchange' => 'bitmart']))
            : $contractPipelineRepository->getAllSymbolsWithActive15m();

        $tf        = '15m';
        $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($tf); // 15
        $cutoff    = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes);
        $end       = $cutoff;
        $endTs     = $end->getTimestamp();

        $windowMinutes = (self::LIMIT_KLINES - 1) * $tfMinutes;
        $minBackfillTs = $end->modify("-{$windowMinutes} minutes")->getTimestamp();

        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        $step = $this->stepFor($tf); // 900

        foreach ($symbols as $symbol) {
            $last = $klines->findOneBy(['contract' => $symbol, 'step' => $step], ['timestamp' => 'DESC']);

            $startTs = $minBackfillTs;
            if ($last) {
                $lastTs = $last->getTimestamp()->getTimestamp();
                $nextAlignedTs = $lastTs + $tfMinutes * 60;
                $startTs = max($nextAlignedTs, $minBackfillTs);
            }

            if ($startTs >= $endTs) { continue; }

            $start = (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'));
            $endDT = (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'));

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $symbol,
                timeframe: $tf,
                limit: self::LIMIT_KLINES,
                start: $start,
                end: $endDT,
                note: 'cron '.$tf
            );
            $sent++;
        }

        $logger->info('[BitmartCron] 15m dispatched', [
            'cutoff'        => $end->format(\DateTimeInterface::RFC3339_EXTENDED),
            'cutoffTs'      => $endTs,
            'minBackfillTs' => $minBackfillTs,
            'sent'          => $sent,
        ]);

        return $this->json([
            'status'       => 'ok',
            'timeframe'    => $tf,
            'cutoff_open'  => $end->format('c'),
            'sent_signals' => $sent,
        ]);
    }

    #[Route('/api/cron/bitmart/refresh-5m', name: 'bitmart_cron_refresh_5m', methods: ['POST'])]
    public function refresh5m(
        ContractPipelineRepository $contractPipelineRepository,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
        Request $request,
    ): JsonResponse {
        $runGuard = $this->guard();
        if ($runGuard !== null) {
            return $runGuard;
        }
        $symbols = ($request->query->has('all') && $request->query->get('all'))
            ? array_map(fn($c) => $c->getSymbol(), $this->contractRepository->findBy(['exchange' => 'bitmart']))
            : $contractPipelineRepository->getAllSymbolsWithActive5m();

        $tf        = '5m';
        $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($tf); // 5
        $cutoff    = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes);
        $end       = $cutoff;
        $endTs     = $end->getTimestamp();

        $windowMinutes = (self::LIMIT_KLINES - 1) * $tfMinutes;
        $minBackfillTs = $end->modify("-{$windowMinutes} minutes")->getTimestamp();

        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        $step = $this->stepFor($tf); // 300

        foreach ($symbols as $symbol) {
            $last = $klines->findOneBy(['contract' => $symbol, 'step' => $step], ['timestamp' => 'DESC']);

            $startTs = $minBackfillTs;
            if ($last) {
                $lastTs = $last->getTimestamp()->getTimestamp();
                $nextAlignedTs = $lastTs + $tfMinutes * 60;
                $startTs = max($nextAlignedTs, $minBackfillTs);
            }

            if ($startTs >= $endTs) { continue; }

            $start = (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'));
            $endDT = (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'));

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $symbol,
                timeframe: $tf,
                limit: self::LIMIT_KLINES,
                start: $start,
                end: $endDT,
                note: 'cron '.$tf
            );
            $sent++;
        }

        $logger->info('[BitmartCron] 5m dispatched', [
            'cutoff'        => $end->format(\DateTimeInterface::RFC3339_EXTENDED),
            'cutoffTs'      => $endTs,
            'minBackfillTs' => $minBackfillTs,
            'sent'          => $sent,
        ]);

        return $this->json([
            'status'       => 'ok',
            'timeframe'    => $tf,
            'cutoff_open'  => $end->format('c'),
            'sent_signals' => $sent,
        ]);
    }

    #[Route('/api/cron/bitmart/refresh-1m', name: 'bitmart_cron_refresh_1m', methods: ['POST'])]
    public function refresh1m(
        ContractPipelineRepository $contractPipelineRepository,
        KlineRepository $klines,
        BitmartOrchestrator $orchestrator,
        LoggerInterface $logger,
        Request $request,
    ): JsonResponse {
        $runGuard = $this->guard();
        if ($runGuard !== null) {
            return $runGuard;
        }
        $symbols = ($request->query->has('all') && $request->query->get('all'))
            ? array_map(fn($c) => $c->getSymbol(), $this->contractRepository->findBy(['exchange' => 'bitmart']))
            : $contractPipelineRepository->getAllSymbolsWithActive1m();

        $tf        = '1m';
        $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($tf); // 1
        $cutoff    = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes);
        $end       = $cutoff;
        $endTs     = $end->getTimestamp();

        $windowMinutes = (self::LIMIT_KLINES - 1) * $tfMinutes;
        $minBackfillTs = $end->modify("-{$windowMinutes} minutes")->getTimestamp();

        $ref = new WorkflowRef(
            id: 'api-rate-limiter-workflow',
            type: 'ApiRateLimiterClient',
            taskQueue: 'api_rate_limiter_queue'
        );

        $sent = 0;
        $step = $this->stepFor($tf); // 60

        foreach ($symbols as $symbol) {
            $last = $klines->findOneBy(['contract' => $symbol, 'step' => $step], ['timestamp' => 'DESC']);

            $startTs = $minBackfillTs;
            if ($last) {
                $lastTs = $last->getTimestamp()->getTimestamp();
                $nextAlignedTs = $lastTs + $tfMinutes * 60;
                $startTs = max($nextAlignedTs, $minBackfillTs);
            }

            if ($startTs >= $endTs) { continue; }

            $start = (new \DateTimeImmutable('@'.$startTs))->setTimezone(new \DateTimeZone('UTC'));
            $endDT = (new \DateTimeImmutable('@'.$endTs))->setTimezone(new \DateTimeZone('UTC'));

            $orchestrator->requestGetKlines(
                $ref,
                baseUrl: self::BASE_URL,
                callback: self::CALLBACK,
                contract: $symbol,
                timeframe: $tf,
                limit: self::LIMIT_KLINES,
                start: $start,
                end: $endDT,
                note: 'cron '.$tf
            );
            $sent++;
        }

        $logger->info('[BitmartCron] 1m dispatched', [
            'cutoff'        => $end->format(\DateTimeInterface::RFC3339_EXTENDED),
            'cutoffTs'      => $endTs,
            'minBackfillTs' => $minBackfillTs,
            'sent'          => $sent,
        ]);

        return $this->json([
            'status'       => 'ok',
            'timeframe'    => $tf,
            'cutoff_open'  => $end->format('c'),
            'sent_signals' => $sent,
        ]);
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

    private function guard(): ?JsonResponse
    {
        if ($this->runtimeGuardRepository->isPaused()) {
            return new JsonResponse(['status' => 'paused'], 200);
        }

        return null;
    }
}
