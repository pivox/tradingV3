<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Domain\Position\Service\PositionUpdateService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class PositionWebhookController
{
    public function __construct(
        private readonly PositionUpdateService $positionUpdateService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/bitmart/positions', name: 'api_bitmart_positions', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return new JsonResponse(['status' => 'ignored', 'reason' => 'empty_body'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($content, true);
        if (!\is_array($payload)) {
            return new JsonResponse(['status' => 'ignored', 'reason' => 'invalid_json'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $channel = (string)($payload['channel'] ?? $payload['group'] ?? '');
        if (strtolower($channel) !== 'futures/position') {
            return new JsonResponse(['status' => 'ignored', 'reason' => 'unsupported_channel']);
        }

        $events = $this->normalizeEvents($payload['data'] ?? []);
        foreach ($events as $event) {
            $this->positionUpdateService->handleEvent($event);
        }

        $this->logger->debug('[PositionWebhook] Events processed', [
            'count' => count($events),
        ]);

        return new JsonResponse(['status' => 'ok', 'processed' => count($events)]);
    }

    /**
     * @param mixed $data
     * @return array<int,array<string,mixed>>
     */
    private function normalizeEvents(mixed $data): array
    {
        $rows = [];
        if (!\is_array($data)) {
            return $rows;
        }

        // Supporte { data: [...] } ou déjà un tableau de positions
        if (isset($data['data']) && \is_array($data['data'])) {
            $data = $data['data'];
        }

        foreach ($data as $row) {
            if (!\is_array($row)) { continue; }

            $symbol = (string)($row['symbol'] ?? $row['contract_symbol'] ?? '');
            $sideRaw = strtolower((string)($row['side'] ?? $row['hold_side'] ?? $row['position_side'] ?? ''));
            $side = match ($sideRaw) {
                'long' => 'LONG',
                'short' => 'SHORT',
                default => strtoupper($sideRaw),
            };

            $size = $row['hold_volume'] ?? $row['size'] ?? $row['position_size'] ?? null;
            $avg = $row['avg_cost'] ?? $row['entry_price'] ?? $row['avg_entry_price'] ?? null;
            $lev = $row['leverage'] ?? null;
            $unreal = $row['unrealised'] ?? $row['unrealized_pnl'] ?? $row['unrealized'] ?? null;
            $tsMs = $row['update_time'] ?? $row['timestamp'] ?? null;

            $rows[] = [
                'symbol' => $symbol,
                'side' => $side,
                'size' => $size,
                'avg_price' => $avg,
                'leverage' => $lev,
                'unrealized_pnl' => $unreal,
                'event_time_ms' => \is_numeric($tsMs) ? (int)$tsMs : null,
                'raw' => $row,
            ];
        }

        return $rows;
    }
}


