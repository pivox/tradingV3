<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BatchRunItem;
use App\Service\BatchRunOrchestrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class BatchRunController extends AbstractController
{
    public function __construct(private readonly BatchRunOrchestrator $orchestrator) {}

    /**
     * Triggeré par Temporal Schedule: ask-for-refresh-{tf}
     * ex: POST /api/batch/ask-for-refresh/4h
     */
    #[Route('/api/batch/ask-for-refresh/{tf}', name: 'api_batch_ask_for_refresh', methods: ['POST'])]
    public function askForRefresh(string $tf): JsonResponse
    {
        if (!in_array($tf, ['4h', '1h', '15m', '5m', '1m'], true)) {
            return $this->json(['ok' => false, 'error' => 'Invalid timeframe'], 400);
        }

        $this->orchestrator->askForRefresh($tf);
        return $this->json(['ok' => true, 'tf' => $tf]);
    }

    /**
     * Callback BitMart klines (api_rate_limiter → kline-callback)
     * Body JSON minimal attendu:
     *  {
     *    "batch_run_id": 123,
     *    "symbol": "BTCUSDT",
     *    "status": "done" | "failed" | "skipped",
     *    "error": "..." // optionnel
     *  }
     */
    // #[Route('/api/callback/bitmart/get-kline', name: 'api_callback_kline_v1', methods: ['POST'])]
    #[Route('/api/v2/callback/bitmart/get-kline', name: 'api_callback_kline', methods: ['POST'])]
    public function klineCallback(Request $req): JsonResponse
    {
        $data = json_decode($req->getContent(), true) ?? [];
        $batchId = (int)($data['batch_run_id'] ?? 0);
        $symbol  = (string)($data['symbol'] ?? '');
        $status  = (string)($data['status'] ?? '');

        if (!$batchId || !$symbol || !in_array($status, [BatchRunItem::STATUS_DONE, BatchRunItem::STATUS_FAILED, BatchRunItem::STATUS_SKIPPED], true)) {
            return $this->json(['ok'=>false, 'error'=>'invalid payload'], 400);
        }

        $this->orchestrator->handleKlineCallback(
            batchRunId: $batchId,
            symbol: $symbol,
            terminalStatus: $status,
            error: $data['error'] ?? null
        );

        return $this->json(['ok'=>true]);
    }
}
