<?php

declare(strict_types=1);

namespace App\Trading\Controller\Api;

use App\TradingCore\Config\EffectiveTradingConfigReadService;
use App\TradingCore\Config\Exception\TradingConfigException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EffectiveTradingConfigApiController extends AbstractController
{
    public function __construct(
        private readonly EffectiveTradingConfigReadService $readService,
    ) {
    }

    #[Route('/api/trading/config/effective', name: 'api_trading_config_effective', methods: ['GET'])]
    public function effective(Request $request): JsonResponse
    {
        $mode = $this->requiredQueryString($request, 'mode');
        $exchange = $this->requiredQueryString($request, 'exchange');
        $env = $this->requiredQueryString($request, 'env');

        $missing = [];
        foreach (['mode' => $mode, 'exchange' => $exchange, 'env' => $env] as $name => $value) {
            if ($value === null) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            return $this->json([
                'error' => [
                    'code' => 'missing_query_parameter',
                    'message' => 'Query parameters "mode", "exchange" and "env" are required.',
                    'missing' => $missing,
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->readService->describe($mode, $exchange, $env));
        } catch (TradingConfigException $exception) {
            return $this->json([
                'error' => [
                    'code' => 'invalid_config_request',
                    'message' => $exception->getMessage(),
                ],
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    private function requiredQueryString(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
