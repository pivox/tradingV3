<?php

declare(strict_types=1);

namespace App\Provider\Controller;

use App\Common\Enum\Exchange;
use App\Provider\Service\PositionProtectionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
final class PositionProtectionController
{
    public function __construct(
        private readonly PositionProtectionService $protectionService,
    ) {
    }

    #[Route('/api/provider/positions/protection', name: 'app_provider_positions_protection_modify', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return new JsonResponse(['status' => 'error', 'reason' => 'empty_body'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($content, true);
        if (!\is_array($payload)) {
            return new JsonResponse(['status' => 'error', 'reason' => 'invalid_json'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $exchangeValue = strtolower((string) ($payload['exchange'] ?? ''));
        $exchange = Exchange::tryFrom($exchangeValue);
        if ($exchange === null) {
            return new JsonResponse(['status' => 'error', 'reason' => 'unsupported_exchange'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($exchange !== Exchange::BITMART) {
            return new JsonResponse(['status' => 'error', 'reason' => 'exchange_not_supported'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $symbol = trim((string) ($payload['symbol'] ?? ''));
        if ($symbol === '') {
            return new JsonResponse(['status' => 'error', 'reason' => 'missing_symbol'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $orderIdRaw = $payload['plan_order_id'] ?? $payload['order_id'] ?? null;
        $planOrderId = $orderIdRaw !== null ? trim((string) $orderIdRaw) : '';
        if ($planOrderId === '') {
            return new JsonResponse(['status' => 'error', 'reason' => 'missing_order_identifier'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $stopLossPrice = $this->extractPrice($payload, ['stop_loss_price', 'stop_loss', 'preset_stop_loss_price']);
        $takeProfitPrice = $this->extractPrice($payload, ['take_profit_price', 'take_profit', 'preset_take_profit_price']);

        if ($stopLossPrice === null && $takeProfitPrice === null) {
            return new JsonResponse(['status' => 'error', 'reason' => 'missing_protection_values'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $clientOrderId = null;
        if (isset($payload['client_order_id'])) {
            $candidate = trim((string) $payload['client_order_id']);
            if ($candidate !== '') {
                $clientOrderId = $candidate;
            }
        }

        try {
            $result = $this->protectionService->modifyProtection(
                $exchange,
                $symbol,
                $planOrderId,
                $clientOrderId,
                $stopLossPrice,
                $takeProfitPrice
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['status' => 'error', 'reason' => 'invalid_payload', 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JsonResponse(['status' => 'error', 'reason' => 'update_failed', 'message' => $e->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'status' => 'ok',
            'exchange' => $exchange->value,
            'symbol' => strtoupper($symbol),
            'plan_order_id' => $planOrderId,
            'result_code' => $result['code'] ?? null,
            'response' => $result,
        ]);
    }

    /**
     * @return string|float|null
     */
    private function extractPrice(array $payload, array $keys): string|float|null
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return $value;
            }

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return null;
    }
}
