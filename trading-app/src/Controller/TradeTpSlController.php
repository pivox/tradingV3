<?php
declare(strict_types=1);

namespace App\Controller;

use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\TradeEntry\Service\TpSlTwoTargetsService;
use App\TradeEntry\Types\Side;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/trade-tpsl', name: 'trade_tpsl_')]
final class TradeTpSlController extends AbstractController
{
    public function __construct(
        private readonly TpSlTwoTargetsService $service,
    ) {}

    #[Route('/two-targets', name: 'two_targets', methods: ['POST'])]
    public function twoTargets(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        foreach (['symbol', 'side'] as $field) {
            if (!isset($data[$field])) {
                return new JsonResponse(['error' => "Missing field: $field"], 400);
            }
        }

        try {
            $side = is_string($data['side']) ? Side::from(strtolower($data['side'])) : $data['side'];
        } catch (\ValueError) {
            return new JsonResponse(['error' => 'Invalid side value'], 400);
        }
        if (!$side instanceof Side) {
            return new JsonResponse(['error' => 'Invalid side value'], 400);
        }

        $split = $this->normalizeSplit($data['split_pct'] ?? null);
        $slFullSize = $this->normalizeNullableBool($data['sl_full_size'] ?? null);
        $pullbackClear = $this->normalizeNullableBool($data['pullback_clear'] ?? null);
        $lateEntry = $this->normalizeNullableBool($data['late_entry'] ?? null);
        $mtfValidCount = isset($data['mtf_valid_count']) ? max(0, min(3, (int)$data['mtf_valid_count'])) : null;
        $momentum = isset($data['momentum']) ? strtolower((string)$data['momentum']) : null;
        $decisionKey = isset($data['decision_key']) ? (string)$data['decision_key'] : null;

        $dto = new TpSlTwoTargetsRequest(
            symbol: (string)$data['symbol'],
            side: $side,
            entryPrice: isset($data['entry_price']) ? (float)$data['entry_price'] : null,
            size: isset($data['size']) ? (int)$data['size'] : null,
            rMultiple: isset($data['r_multiple']) ? (float)$data['r_multiple'] : null,
            splitPct: $split,
            cancelExistingStopLossIfDifferent: isset($data['cancel_sl_if_diff']) ? (bool)$data['cancel_sl_if_diff'] : true,
            cancelExistingTakeProfits: isset($data['cancel_tp']) ? (bool)$data['cancel_tp'] : true,
            slFullSize: $slFullSize,
            momentum: $momentum,
            mtfValidCount: $mtfValidCount,
            pullbackClear: $pullbackClear,
            lateEntry: $lateEntry,
        );

        try {
            $result = ($this->service)($dto, $decisionKey);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Failed: ' . $e->getMessage()], 500);
        }

        return new JsonResponse([
            'symbol' => $dto->symbol,
            'side' => $side->value,
            'decision_key' => $decisionKey,
            'sl' => $result['sl'],
            'tp1' => $result['tp1'],
            'tp2' => $result['tp2'],
            'submitted' => $result['submitted'],
            'cancelled' => $result['cancelled'],
        ]);
    }

    private function normalizeSplit(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $split = (float)$value;
        if ($split > 1.0 && $split <= 100.0) {
            $split *= 0.01;
        }

        return max(0.0, min(1.0, $split));
    }

    private function normalizeNullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = strtolower($value);
            if ($value === 'auto') {
                return null;
            }
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int)$value) !== 0;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $filtered;
    }
}
