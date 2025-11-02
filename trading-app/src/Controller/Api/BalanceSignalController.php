<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Trading\Balance\Dto\WorkerBalanceSignalDto;
use App\Domain\Trading\Balance\BalanceSignalService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class BalanceSignalController
{
    private const MAX_SKEW_MS = 60_000;
    private string $sharedSecret;

    public function __construct(
        private readonly BalanceSignalService $balanceService,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
        $this->sharedSecret = (string) ($_ENV['WS_WORKER_SHARED_SECRET'] ?? $_SERVER['WS_WORKER_SHARED_SECRET'] ?? '');
    }

    #[Route('/api/ws-worker/balance', name: 'api_ws_worker_balance', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = (string) $request->getContent();

        if (!$this->validateSignature($request, $body)) {
            return new JsonResponse(['status' => 'error', 'code' => 'auth_failed'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            return new JsonResponse(['status' => 'error', 'code' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $signal = WorkerBalanceSignalDto::fromArray($payload);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'status' => 'error',
                'code' => 'invalid_payload',
                'message' => $exception->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->balanceService->process($signal);
        } catch (\RuntimeException $exception) {
            $this->logger->warning('[BalanceSignal] Failed to process balance', [
                'asset' => $signal->asset,
                'trace_id' => $signal->traceId,
                'error' => $exception->getMessage(),
            ]);

            return new JsonResponse([
                'status' => 'error',
                'code' => 'processing_failed',
                'message' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'status' => 'accepted',
            'asset' => $signal->asset,
            'trace_id' => $signal->traceId,
            'timestamp' => $signal->timestamp->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_ACCEPTED);
    }

    private function validateSignature(Request $request, string $body): bool
    {
        if ($this->sharedSecret === '') {
            $this->logger->warning('[BalanceSignal] WS_WORKER_SHARED_SECRET not configured, using fallback mode');
            return true; // fallback debug mode
        }

        $timestamp = $request->headers->get('X-WS-Worker-Timestamp');
        $signature = $request->headers->get('X-WS-Worker-Signature');

        if ($timestamp === null || $signature === null) {
            $this->logger->warning('[BalanceSignal] Missing authentication headers');
            return false;
        }

        if (!$this->isTimestampFresh($timestamp)) {
            $this->logger->warning('[BalanceSignal] Stale timestamp', ['timestamp' => $timestamp]);
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . "\n" . $body, $this->sharedSecret);
        if (!hash_equals($expected, $signature)) {
            $this->logger->warning('[BalanceSignal] Invalid signature', ['timestamp' => $timestamp]);
            return false;
        }

        return true;
    }

    private function isTimestampFresh(string $timestampHeader): bool
    {
        if (!ctype_digit($timestampHeader)) {
            return false;
        }

        $timestamp = (int) $timestampHeader;
        $now = $this->clock->now();
        $nowMs = ((int) $now->format('U')) * 1000 + (int) $now->format('v');

        return abs($nowMs - $timestamp) <= self::MAX_SKEW_MS;
    }
}

