<?php
declare(strict_types=1);

namespace App\Controller;

use App\TradeEntry\TradeEntryBox;
use App\TradeEntry\Types\Side;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/trade-entry', name: 'trade_entry_')]
final class TradeEntryController extends AbstractController
{
    public function __construct(
        private TradeEntryBox $tradeEntryBox
    ) {}

    #[Route('/execute', name: 'execute', methods: ['POST'])]
    public function execute(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }

            // Validation minimale des champs requis
            $required = ['symbol', 'side', 'entry_price_base', 'atr_value', 'pivot_price', 'risk_pct', 'budget_usdt', 'equity_usdt'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    return new JsonResponse(['error' => "Missing required field: $field"], 400);
                }
            }

            // Conversion du side string vers enum
            if (is_string($data['side'])) {
                $data['side'] = Side::from($data['side']);
            }

            $result = $this->tradeEntryBox->handle($data);

            return new JsonResponse([
                'status' => $result->status,
                'data' => $result->data
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/test', name: 'test', methods: ['GET'])]
    public function test(): JsonResponse
    {
        // Test avec des données d'exemple
        $testData = [
            'symbol' => 'BTCUSDT',
            'side' => Side::LONG,
            'entry_price_base' => 67250.0,
            'atr_value' => 35.0,
            'pivot_price' => 67220.0,
            'risk_pct' => 2.0,
            'budget_usdt' => 100.0,
            'equity_usdt' => 1000.0,
            'rsi' => 54.0,
            'volume_ratio' => 1.8,
            'pullback_confirmed' => true,
        ];

        $result = $this->tradeEntryBox->handle($testData);

        return new JsonResponse([
            'test_data' => $testData,
            'result' => [
                'status' => $result->status,
                'data' => $result->data
            ]
        ]);
    }
}
