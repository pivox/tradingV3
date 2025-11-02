<?php
declare(strict_types=1);

namespace App\Controller;

use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Service\TradeEntryService;
use App\TradeEntry\Types\Side;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Route('/api/trade-entry', name: 'trade_entry_')]
final class TradeEntryController extends AbstractController
{
    public function __construct(
        private readonly TradeEntryService $service,
        #[Autowire(service: 'App\\Config\\MtfValidationConfig')]
        private readonly \App\Config\MtfValidationConfig $mtfConfig,
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
            $required = ['symbol', 'side'];
            foreach ($required as $field) {
                if (!isset($data[$field])) {
                    return new JsonResponse(['error' => "Missing required field: $field"], 400);
                }
            }

            try {
                $side = is_string($data['side']) ? Side::from(strtolower($data['side'])) : $data['side'];
            } catch (\ValueError $error) {
                return new JsonResponse(['error' => 'Invalid side value'], 400);
            }

            if (!$side instanceof Side) {
                return new JsonResponse(['error' => 'Invalid side value'], 400);
            }

            $defaults = $this->mtfConfig->getDefaults();
            $riskPctDefault = (float)($defaults['risk_pct_percent'] ?? 2.0);
            $riskPct = isset($data['risk_pct']) ? (float)$data['risk_pct'] : $riskPctDefault;
            if ($riskPct > 1.0) {
                $riskPct /= 100;
            }

            $marketSpreadDefault = (float)($defaults['market_max_spread_pct'] ?? 0.001);
            $marketSpread = isset($data['market_max_spread_pct']) ? (float)$data['market_max_spread_pct'] : $marketSpreadDefault;
            if ($marketSpread > 1.0) {
                $marketSpread /= 100;
            }

            $requestDto = new TradeEntryRequest(
                symbol: (string)$data['symbol'],
                side: $side,
                orderType: $data['order_type'] ?? ($defaults['order_type'] ?? 'limit'),
                openType: $data['open_type'] ?? ($defaults['open_type'] ?? 'isolated'),
                orderMode: (int)($data['order_mode'] ?? ($defaults['order_mode'] ?? 1)),
                initialMarginUsdt: (float)($data['initial_margin_usdt'] ?? ($defaults['initial_margin_usdt'] ?? 100.0)),
                riskPct: $riskPct,
                rMultiple: isset($data['r_multiple']) ? (float)$data['r_multiple'] : (float)($defaults['r_multiple'] ?? 2.0),
                entryLimitHint: isset($data['entry_limit_hint']) ? (float)$data['entry_limit_hint'] : null,
                stopFrom: $data['stop_from'] ?? ($defaults['stop_from'] ?? 'risk'),
                atrValue: isset($data['atr_value']) ? (float)$data['atr_value'] : null,
                atrK: isset($data['atr_k']) ? (float)$data['atr_k'] : (float)($defaults['atr_k'] ?? 1.5),
                marketMaxSpreadPct: $marketSpread,
            );

            $result = $this->service->buildAndExecute($requestDto);

            return new JsonResponse([
                'status' => $result->status,
                'client_order_id' => $result->clientOrderId,
                'exchange_order_id' => $result->exchangeOrderId,
                'raw' => $result->raw,
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
        $requestDto = new TradeEntryRequest(
            symbol: 'BTCUSDT',
            side: Side::Long,
            orderType: 'limit',
            entryLimitHint: 67250.0,
            atrValue: 35.0,
        );

        $result = $this->service->buildAndExecute($requestDto);

        return new JsonResponse([
            'status' => $result->status,
            'client_order_id' => $result->clientOrderId,
            'exchange_order_id' => $result->exchangeOrderId,
            'raw' => $result->raw,
        ]);
    }
}
