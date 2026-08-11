<?php

declare(strict_types=1);

namespace App\Trading\Controller\Api;

use App\TradingCore\Config\EffectiveTradingConfigReadService;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\Exception\NonExecutableTradingConfigException;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EffectiveTradingConfigApiController extends AbstractController
{
    private const FIELDS = ['mode_id', 'mode_version', 'setup_id', 'setup_version', 'exchange', 'environment', 'side'];

    public function __construct(private readonly EffectiveTradingConfigReadService $readService)
    {
    }

    #[Route('/api/trading/config/effective', name: 'api_trading_config_effective', methods: ['GET'])]
    public function effective(Request $request): JsonResponse
    {
        $values = [];
        $missing = [];
        foreach (self::FIELDS as $field) {
            $value = $request->query->get($field);
            if (!is_string($value) || trim($value) === '') {
                $missing[] = $field;
                continue;
            }
            $values[$field] = trim($value);
        }
        if ($missing !== []) {
            return $this->json(['error' => ['code' => 'missing_query_parameter', 'message' => 'All canonical identity fields are required.', 'missing' => $missing]], Response::HTTP_BAD_REQUEST);
        }

        try {
            $capability = null;
            $capabilityValue = $request->query->get('execution_capability');
            if ($capabilityValue !== null) {
                if (!is_string($capabilityValue) || trim($capabilityValue) === '') {
                    throw new TradingConfigException('execution_capability must be a non-empty string.');
                }
                $capability = ShadowExecutionCapability::tryFrom(trim($capabilityValue));
                if ($capability === null) {
                    throw new TradingConfigException('Unknown execution_capability; no fallback is allowed.');
                }
            }
            $identity = new EffectiveTradingConfigRequest(
                $values['mode_id'], $values['mode_version'], $values['setup_id'], $values['setup_version'],
                $values['exchange'], $values['environment'], $values['side'], $capability,
            );
            return $this->json($this->readService->describe($identity));
        } catch (NonExecutableTradingConfigException $exception) {
            return $this->json([
                'request' => $exception->request->toArray(),
                'config_hash' => null,
                'condition_catalog_hash' => null,
                'ordered_layers' => [],
                'ordered_files' => [],
                'provenance' => [],
                'executable' => false,
                'blockers' => $exception->blockers,
                'error' => ['code' => 'config_not_executable', 'message' => $exception->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TradingConfigException $exception) {
            return $this->json(['error' => ['code' => 'invalid_config_request', 'message' => $exception->getMessage()]], Response::HTTP_BAD_REQUEST);
        }
    }
}
