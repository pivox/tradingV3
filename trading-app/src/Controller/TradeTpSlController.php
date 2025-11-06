<?php
declare(strict_types=1);

namespace App\Controller;

use App\Config\MtfValidationConfig;
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
        private readonly MtfValidationConfig $mtfConfig,
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

        // Clamp split_pct to [0,1]
        $split = isset($data['split_pct']) ? (float)$data['split_pct'] : null;
        if ($split !== null) {
            if ($split > 1.0 && $split <= 100.0) {
                $split *= 0.01;
            }
            $split = max(0.0, min(1.0, $split));
        }

        $dto = new TpSlTwoTargetsRequest(
            symbol: (string)$data['symbol'],
            side: $side,
            entryPrice: isset($data['entry_price']) ? (float)$data['entry_price'] : null,
            size: isset($data['size']) ? (int)$data['size'] : null,
            rMultiple: isset($data['r_multiple']) ? (float)$data['r_multiple'] : null,
            splitPct: $split,
            cancelExistingStopLossIfDifferent: isset($data['cancel_sl_if_diff']) ? (bool)$data['cancel_sl_if_diff'] : true,
            cancelExistingTakeProfits: isset($data['cancel_tp']) ? (bool)$data['cancel_tp'] : true,
            slFullSize: isset($data['sl_full_size']) ? (bool)$data['sl_full_size'] : null,
            momentum: isset($data['momentum']) ? (string)$data['momentum'] : null,
            mtfValidCount: isset($data['mtf_valid_count']) ? (int)$data['mtf_valid_count'] : null,
            pullbackClear: isset($data['pullback_clear']) ? (bool)$data['pullback_clear'] : null,
            lateEntry: isset($data['late_entry']) ? (bool)$data['late_entry'] : null,
            dryRun: isset($data['dry_run']) ? (bool)$data['dry_run'] : false,
        );

        try {
            $result = ($this->service)($dto);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Failed: ' . $e->getMessage()], 500);
        }

        return new JsonResponse([
            'symbol' => $dto->symbol,
            'side' => $side->value,
            'dry_run' => $dto->dryRun ?? false,
            'sl' => $result['sl'],
            'tp1' => $result['tp1'],
            'tp2' => $result['tp2'],
            'submitted' => $result['submitted'],
            'cancelled' => $result['cancelled'],
        ]);
    }
}
