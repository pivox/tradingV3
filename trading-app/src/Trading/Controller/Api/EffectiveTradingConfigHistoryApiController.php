<?php

declare(strict_types=1);

namespace App\Trading\Controller\Api;

use App\TradingCore\Config\Audit\EffectiveConfigSnapshotNotFound;
use App\TradingCore\Config\EffectiveTradingConfigReadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EffectiveTradingConfigHistoryApiController extends AbstractController
{
    public function __construct(private readonly EffectiveTradingConfigReadService $readService)
    {
    }

    #[Route('/api/trading/config/effective/snapshots/{snapshot_hash}', name: 'api_trading_config_effective_snapshot', methods: ['GET'])]
    public function snapshot(string $snapshot_hash): JsonResponse
    {
        try {
            return $this->json($this->readService->historical($snapshot_hash));
        } catch (\InvalidArgumentException) {
            return $this->invalidHash();
        } catch (EffectiveConfigSnapshotNotFound $exception) {
            return $this->notFound($exception);
        }
    }

    #[Route('/api/trading/config/effective/snapshots', name: 'api_trading_config_effective_snapshots', methods: ['GET'])]
    public function snapshots(Request $request): JsonResponse
    {
        $configHash = $request->query->get('config_hash');
        if (!is_string($configHash) || $configHash === '') {
            return $this->missing(['config_hash']);
        }
        try {
            $snapshots = $this->readService->history($configHash);

            return $this->json(['count' => count($snapshots), 'snapshots' => $snapshots]);
        } catch (\InvalidArgumentException) {
            return $this->invalidHash();
        }
    }

    #[Route('/api/trading/config/effective/diff', name: 'api_trading_config_effective_diff', methods: ['GET'])]
    public function diff(Request $request): JsonResponse
    {
        $values = [];
        $missing = [];
        foreach (['left', 'right'] as $field) {
            $value = $request->query->get($field);
            if (!is_string($value) || $value === '') {
                $missing[] = $field;
            } else {
                $values[$field] = $value;
            }
        }
        if ($missing !== []) {
            return $this->missing($missing);
        }
        try {
            return $this->json($this->readService->diff($values['left'], $values['right']));
        } catch (\InvalidArgumentException) {
            return $this->invalidHash();
        } catch (EffectiveConfigSnapshotNotFound $exception) {
            return $this->notFound($exception);
        }
    }

    private function invalidHash(): JsonResponse
    {
        return $this->json(['error' => ['code' => 'invalid_config_hash', 'message' => 'A canonical sha256 identifier is required.']], Response::HTTP_BAD_REQUEST);
    }

    private function notFound(EffectiveConfigSnapshotNotFound $exception): JsonResponse
    {
        return $this->json(['error' => ['code' => 'effective_config_snapshot_not_found', 'message' => $exception->getMessage(), 'snapshot_hash' => $exception->snapshotHash]], Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $missing */
    private function missing(array $missing): JsonResponse
    {
        return $this->json(['error' => ['code' => 'missing_query_parameter', 'message' => 'Required query parameters are missing.', 'missing' => $missing]], Response::HTTP_BAD_REQUEST);
    }
}
