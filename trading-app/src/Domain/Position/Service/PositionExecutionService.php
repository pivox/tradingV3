<?php

declare(strict_types=1);

namespace App\Domain\Position\Service;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Position\Dto\PositionOpeningDto;
use App\Domain\Position\Dto\PositionConfigDto;
use App\Domain\Ports\Out\TradingProviderPort;
use App\Domain\Trading\Exposure\ActiveExposureGuard;
use App\Domain\Trading\Order\OrderLifecycleService;
use App\Domain\Leverage\Service\SymbolLeverageRegistry;
use App\Repository\MtfLockRepository;
use App\Repository\ContractRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PositionExecutionService
{
    public function __construct(
        private readonly TradingProviderPort $tradingProvider,
        private readonly ActiveExposureGuard $exposureGuard,
        private readonly OrderLifecycleService $orderLifecycle,
        private readonly ContractRepository $contractRepository,
        private readonly SymbolLeverageRegistry $symbolLeverageRegistry,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $positionsFlowLogger,
        private readonly ClockInterface $clock,
        private readonly MtfLockRepository $mtfLockRepository
    ) {
    }

    public function executePosition(PositionOpeningDto $position, PositionConfigDto $config): array
    {
        $this->logger->info('[Position Execution] Starting position execution', [
            'symbol' => $position->symbol,
            'side' => $position->side->value,
            'position_size' => $position->positionSize,
            'leverage' => $position->leverage,
            'dry_run' => $config->dryRun
        ]);

        if ($config->dryRun) {
            return $this->simulatePositionExecution($position, $config);
        }

        return $this->executeRealPosition($position, $config);
    }

    private function simulatePositionExecution(PositionOpeningDto $position, PositionConfigDto $config): array
    {
        $this->logger->info('[Position Execution] Simulating position execution (dry run)', [
            'symbol' => $position->symbol
        ]);

        // Simulation d'une exécution réussie
        $simulatedOrderId = 'SIM_' . uniqid();
        $simulatedExecutionPrice = $this->simulateExecutionPrice($position->entryPrice, $position->side);

        return [
            'status' => 'success',
            'order_id' => $simulatedOrderId,
            'execution_price' => $simulatedExecutionPrice,
            'position_size' => $position->positionSize,
            'leverage' => $position->leverage,
            'stop_loss_price' => $position->stopLossPrice,
            'take_profit_price' => $position->takeProfitPrice,
            'risk_amount' => $position->riskAmount,
            'potential_profit' => $position->potentialProfit,
            'risk_metrics' => $position->riskMetrics,
            'execution_params' => $position->executionParams,
            'dry_run' => true,
            'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
        ];
    }

    private function executeRealPosition(PositionOpeningDto $position, PositionConfigDto $config): array
    {
        try {
            // 1. Vérifier les conditions préalables
            $this->exposureGuard->assertEligible($position->symbol, $position->side);
            $this->validatePreExecutionConditions($position, $config);

            // 2. Configurer le levier (avec fallback si nécessaire)
            $leverageSetup = $this->setLeverageWithFallback($position, $config);
            $effectiveLeverage = (int) ($leverageSetup['effective_leverage'] ?? max(1, (int) round($position->leverage)));
            $effectiveOpenType = (string) ($leverageSetup['open_type'] ?? 'isolated');

            // 3. Placer l'ordre principal
            try {
                $this->positionsFlowLogger->info('[PositionsFlow] Placing main order', [
                    'symbol' => $position->symbol,
                    'side' => $position->side->value,
                    'order_type' => strtolower($config->orderType),
                    'size' => $position->positionSize,
                    'leverage' => $position->leverage,
                ]);
            } catch (\Throwable) {}

            $mainOrder = $this->placeMainOrderWithFallback($position, $config, $effectiveLeverage, $effectiveOpenType);

            try {
                $this->positionsFlowLogger->info('[PositionsFlow] Main order submitted', [
                    'symbol' => $position->symbol,
                    'order_id' => $mainOrder['order_id'] ?? null,
                    'client_order_id' => $mainOrder['client_order_id'] ?? null,
                    'status' => $mainOrder['status'] ?? null,
                ]);
            } catch (\Throwable) {}

            // 4-5. Si LIMIT avec presets TP/SL, ne pas soumettre d'ordres TP/SL séparés
            $stopLossOrder = null;
            $takeProfitOrder = null;
            if (strtolower($config->orderType) !== 'limit') {
                $stopLossOrder = $this->placeStopLossOrder($position, $config, $effectiveLeverage);
                $takeProfitOrder = $this->placeTakeProfitOrder($position, $config, $effectiveLeverage);
            }

            $this->logger->info('[Position Execution] Position executed successfully', [
                'symbol' => $position->symbol,
                'main_order_id' => $mainOrder['order_id'],
                'stop_loss_order_id' => $stopLossOrder['order_id'] ?? null,
                'take_profit_order_id' => $takeProfitOrder['order_id'] ?? null
            ]);

            return [
                'status' => 'success',
                'main_order' => $mainOrder,
                'stop_loss_order' => $stopLossOrder,
                'take_profit_order' => $takeProfitOrder,
                'position_size' => $position->positionSize,
                'leverage' => $position->leverage,
                'risk_amount' => $position->riskAmount,
                'potential_profit' => $position->potentialProfit,
                'risk_metrics' => $position->riskMetrics,
                'dry_run' => false,
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $this->logger->error('[Position Execution] Failed to execute position', [
                'symbol' => $position->symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'symbol' => $position->symbol,
                'dry_run' => false,
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
            ];
        }
    }

    private function validatePreExecutionConditions(PositionOpeningDto $position, PositionConfigDto $config): void
    {
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Pre-execution validation started', [
                'symbol' => $position->symbol,
                'size' => $position->positionSize,
                'leverage' => $position->leverage,
                'entry_price' => $position->entryPrice,
                'stop_loss_price' => $position->stopLossPrice,
                'take_profit_price' => $position->takeProfitPrice,
            ]);
        } catch (\Throwable) {}
        // Vérifier la taille minimale d'ordre
        if ($position->positionSize < $config->minOrderSize) {
            throw new \InvalidArgumentException(
                sprintf('Position size %f is below minimum order size %f', $position->positionSize, $config->minOrderSize)
            );
        }

        // Vérifier la taille maximale d'ordre (adapter si budget fixe et contrat)
        $effectiveMaxOrderSize = $config->maxOrderSize;
        $isFixedBudget = strtolower($config->budgetMode) === 'fixed_or_available';
        if ($isFixedBudget) {
            $effectiveMaxOrderSize = max($effectiveMaxOrderSize, $position->positionSize);
        }

        $contractMaxOrderSize = null;
        if (isset($position->executionParams['contract_max_order_size']) && is_numeric($position->executionParams['contract_max_order_size'])) {
            $contractMaxOrderSize = (float) $position->executionParams['contract_max_order_size'];
        }
        if ($contractMaxOrderSize !== null && $contractMaxOrderSize > 0.0) {
            $effectiveMaxOrderSize = $effectiveMaxOrderSize > 0.0
                ? min($effectiveMaxOrderSize, $contractMaxOrderSize)
                : $contractMaxOrderSize;
        }

        if ($effectiveMaxOrderSize > 0.0 && $position->positionSize > $effectiveMaxOrderSize) {
            throw new \InvalidArgumentException(
                sprintf('Position size %f exceeds maximum order size %f', $position->positionSize, $effectiveMaxOrderSize)
            );
        }

        // Vérifier le levier
        if ($position->leverage < 1.0 || ($position->leverage > 20.0 && in_array($position->symbol, ['BTC', 'ETH', 'BNB', 'SOL']))) {
            throw new \InvalidArgumentException(
                sprintf('Invalid leverage %f. Must be between 1.0 and 20.0', $position->leverage)
            );
        }

        // Vérifier les prix de stop loss et take profit
        if ($position->stopLossPrice <= 0 || $position->takeProfitPrice <= 0) {
            throw new \InvalidArgumentException('Stop loss and take profit prices must be greater than 0');
        }

        $this->logger->debug('[Position Execution] Pre-execution conditions validated', [
            'symbol' => $position->symbol
        ]);
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Pre-execution validation passed', [
                'symbol' => $position->symbol
            ]);
        } catch (\Throwable) {}
    }

    private function setLeverage(PositionOpeningDto $position, PositionConfigDto $config): void
    {
        $leverageInt = max(1, (int) round($position->leverage));
        $openType = 'isolated'; // forcé isolé

        $this->logger->info('[Position Execution] Setting leverage', [
            'symbol' => $position->symbol,
            'requested_leverage' => $position->leverage,
            'submitted_leverage' => $leverageInt,
            'open_type' => $openType,
        ]);
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Setting leverage', [
                'symbol' => $position->symbol,
                'requested_leverage' => $position->leverage,
                'submitted_leverage' => $leverageInt,
                'open_type' => $openType,
            ]);
        } catch (\Throwable) {}

        $response = $this->tradingProvider->setLeverage($position->symbol, $leverageInt, $openType);
        if ((int)($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('Failed to set leverage: ' . json_encode($response));
        }

        $maxValue = isset($response['data']['max_value']) ? (float) $response['data']['max_value'] : (float) $leverageInt;
        $this->symbolLeverageRegistry->remember($position->symbol, $maxValue);

        $this->logger->debug('[Position Execution] Leverage set successfully', [
            'symbol' => $position->symbol,
            'response' => $response,
        ]);
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Leverage set', [
                'symbol' => $position->symbol,
                'response_code' => $response['code'] ?? null,
                'max_value' => $response['data']['max_value'] ?? null,
            ]);
        } catch (\Throwable) {}
    }

    private function setLeverageWithFallback(PositionOpeningDto $position, PositionConfigDto $config): array
    {
        $requested = max(1, (int) round($position->leverage));
        $resolvedMax = max(1, (int) round($this->symbolLeverageRegistry->resolve($position->symbol)));
        $openType = 'isolated';
        $candidates = [$requested, min($requested, 10), min($requested, 5), $resolvedMax, 3, 2, 1];
        $candidates = array_values(array_unique(array_filter($candidates, static fn($value) => $value !== null && $value > 0)));
        $openTypes = [$openType, 'cross'];

        foreach ($openTypes as $ot) {
            foreach ($candidates as $lev) {
                $lev = max(1, (int) $lev);
                try {
                    $this->positionsFlowLogger->info('[PositionsFlow] Trying leverage candidate', [
                        'symbol' => $position->symbol,
                        'candidate_leverage' => $lev,
                        'open_type' => $ot,
                    ]);
                } catch (\Throwable) {}

                try {
                    $response = $this->tradingProvider->setLeverage($position->symbol, $lev, $ot);
                    if ((int)($response['code'] ?? 0) === 1000) {
                        $maxValue = isset($response['data']['max_value']) ? (float) $response['data']['max_value'] : (float) $lev;
                        $this->symbolLeverageRegistry->remember($position->symbol, $maxValue);
                        try {
                            $this->positionsFlowLogger->info('[PositionsFlow] Leverage accepted', [
                                'symbol' => $position->symbol,
                                'effective_leverage' => $lev,
                                'open_type' => $ot,
                            ]);
                        } catch (\Throwable) {}
                        return ['effective_leverage' => $lev, 'open_type' => $ot];
                    }
                } catch (\Throwable $e) {
                    // Continuer avec le prochain candidat
                }
            }
        }

        throw new RuntimeException('Failed to set leverage with all fallback candidates');
    }

    private function placeMainOrder(PositionOpeningDto $position, PositionConfigDto $config, ?int $submittedLeverageOverride = null, ?string $openTypeOverride = null): array
    {
        $clientOrderId = $this->generateClientOrderId($position->symbol, 'OPEN');
        $orderType = strtolower($config->orderType);
        // Ajuster la taille exécutée si le levier d'exécution diffère du levier calculé
        $submittedLeverage = $submittedLeverageOverride ?? max(1, (int) round($position->leverage));
        $leverageCalc = max(1.0, (float) $position->leverage);
        $execSize = max(1, (int) floor($position->positionSize * ($submittedLeverage / $leverageCalc)));
        $payload = [
            'symbol' => strtoupper($position->symbol),
            'client_order_id' => $clientOrderId,
            'side' => $this->mapOpenSide($position->side),
            'mode' => 1, // BitMart exige un mode (1=GTC)
            'type' => $orderType,
            'open_type' => $openTypeOverride ?? 'isolated',
            // BitMart attend un entier (contrats) pour size
            'size' => $execSize,
            'leverage' => (string) $submittedLeverage,
        ];

        // Paramètres spécifiques LIMIT uniquement (mode/time_in_force/prix/partial)
        if ($orderType === 'limit') {
            $payload['price'] = $this->quantizeToTick($position->symbol, $position->entryPrice);
            $payload['time_in_force'] = strtolower($config->timeInForce);
            if (!$config->enablePartialFills) {
                $payload['allow_partial'] = 0;
            }
            // Presets TP/SL sur l'ordre d'entrée (évite submit-tp-sl-order)
            $payload['preset_take_profit_price_type'] = 2; // mark
            $payload['preset_stop_loss_price_type'] = 2;   // mark
            $payload['preset_take_profit_price'] = $this->quantizeToTick($position->symbol, $position->takeProfitPrice);
            $payload['preset_stop_loss_price'] = $this->quantizeToTick($position->symbol, $position->stopLossPrice);
        }

        $this->logger->info('[Position Execution] Placing main order', $payload);
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Main order payload', $payload);
        } catch (\Throwable) {}

        $response = $this->tradingProvider->submitOrder($payload);
        if ((int)($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('Failed to place main order: ' . json_encode($response));
        }

        $orderData = $response['data'] ?? null;
        $orderId = null;
        if (is_array($orderData)) {
            $orderId = $orderData['order_id']
                ?? $orderData['orderId']
                ?? $orderData['orderIdStr']
                ?? null;

            if ($orderId === null) {
                $orders = $orderData['orders'] ?? null;
                if (is_array($orders) && isset($orders[0]['order_id'])) {
                    $orderId = $orders[0]['order_id'];
                }
            }
        }

        if (!is_string($orderId) && $orderId !== null) {
            $orderId = (string) $orderId;
        }

        if (empty($orderId)) {
            $orderId = $clientOrderId;
            $this->logger->warning('[Position Execution] BitMart response missing order_id, falling back to client_order_id', [
                'symbol' => $position->symbol,
                'client_order_id' => $clientOrderId,
                'response' => $response,
            ]);
            try {
                $this->positionsFlowLogger->warning('[PositionsFlow] Order id missing in response', [
                    'symbol' => $position->symbol,
                    'client_order_id' => $clientOrderId,
                ]);
            } catch (\Throwable) {}
        }

        $context = [
            'symbol' => strtoupper($position->symbol),
            'side' => $position->side->value,
            'size' => $position->positionSize,
            'stop_loss_price' => $position->stopLossPrice,
            'take_profit_price' => $position->takeProfitPrice,
        ];

        $this->orderLifecycle->registerEntryOrder(
            array_merge($payload, ['order_id' => $orderId]),
            $context
        );

        // Créer un lock d'1h sur l'ouverture d'ordre pour éviter les runs concurrents
        try {
            $lockKey = 'mtf_order_lock_'.strtoupper($position->symbol);
            $this->positionsFlowLogger->info('[PositionsFlow] Creating 1h order lock', [
                'lock_key' => $lockKey,
                'symbol' => $position->symbol,
            ]);
            // 3600s = 1 heure
            $this->mtfLockRepository->acquireLock($lockKey, $clientOrderId, 3600, 'order_opened');
        } catch (\Throwable) {}

        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Main order response', [
                'symbol' => $position->symbol,
                'order_id' => $orderId,
                'client_order_id' => $clientOrderId,
                'code' => $response['code'] ?? null,
            ]);
        } catch (\Throwable) {}

        return [
            'order_id' => $orderId,
            'client_order_id' => $clientOrderId,
            'symbol' => $position->symbol,
            'side' => $position->side->value,
            'type' => strtoupper($orderType),
            'size' => $position->positionSize,
            'price' => $position->entryPrice,
            'status' => 'submitted',
            'raw_response' => $response,
        ];
    }

    private function placeMainOrderWithFallback(PositionOpeningDto $position, PositionConfigDto $config, int $effectiveLeverage, string $effectiveOpenType): array
    {
        // Tente la soumission, si 40012 (risk level / leverage not synchronized), resoumet setLeverage + backoff puis réessaie avec levier abaissé
        $candidates = [$effectiveLeverage, max(1, (int)floor($effectiveLeverage * 0.8)), max(1, (int)floor($effectiveLeverage * 0.6)), max(1, (int)floor($effectiveLeverage * 0.4)), 1];
        $tried = [];
        foreach ($candidates as $index => $lev) {
            if (isset($tried[$lev])) { continue; }
            $tried[$lev] = true;
            // S'assurer que le levier côté exchange est synchronisé pour ce palier
            try {
                $this->positionsFlowLogger->info('[PositionsFlow] Ensuring leverage is set before order', [
                    'symbol' => $position->symbol,
                    'target_leverage' => $lev,
                    'open_type' => $effectiveOpenType,
                ]);
            } catch (\Throwable) {}

            try {
                $resp = $this->tradingProvider->setLeverage($position->symbol, $lev, $effectiveOpenType);
                if ((int)($resp['code'] ?? 0) === 1000) {
                    $maxValue = isset($resp['data']['max_value']) ? (float) $resp['data']['max_value'] : (float) $lev;
                    $this->symbolLeverageRegistry->remember($position->symbol, $maxValue);
                }
                // Attendre un court instant pour la synchro côté exchange
                usleep(800000); // 800ms
            } catch (\Throwable) {
                // Même en cas d'erreur, tenter la soumission pour capter le message précis
            }
            try {
                return $this->placeMainOrder($position, $config, $lev, $effectiveOpenType);
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                if (
                    str_contains($msg, '40012') ||
                    str_contains(strtolower($msg), 'risk level') ||
                    str_contains(strtolower($msg), 'not synchronized')
                ) {
                    $nextLev = null;
                    for ($j = $index + 1, $len = count($candidates); $j < $len; $j++) {
                        $candidate = $candidates[$j];
                        if (!isset($tried[$candidate])) {
                            $nextLev = $candidate;
                            break;
                        }
                    }
                    if ($nextLev !== null && $nextLev !== $lev) {
                        try {
                            $this->positionsFlowLogger->warning('[PositionsFlow] Lowering leverage due to risk level', [
                                'symbol' => $position->symbol,
                                'from' => $lev,
                                'to' => $nextLev,
                                'error' => $msg,
                            ]);
                        } catch (\Throwable) {}
                    }
                    // Continuer avec levier inférieur
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Failed to place main order after leverage fallback attempts');
    }

    private function placeStopLossOrder(PositionOpeningDto $position, PositionConfigDto $config, ?int $submittedLeverageOverride = null): ?array
    {
        if (!$config->enableStopLoss) {
            return null;
        }

        $clientOrderId = $this->generateClientOrderId($position->symbol, 'SL');
        $submittedLeverage = $submittedLeverageOverride ?? max(1, (int) round($position->leverage));
        $leverageCalc = max(1.0, (float) $position->leverage);
        $execSize = max(1, (int) floor($position->positionSize * ($submittedLeverage / $leverageCalc)));
        $payload = [
            'symbol' => strtoupper($position->symbol),
            'orderType' => 'stop_loss',
            'type' => 'stop_loss',
            'side' => $this->mapCloseSide($position->side),
            'triggerPrice' => $this->formatDecimal($position->stopLossPrice, 6),
            // Pour category=limit: executivePrice = triggerPrice
            'executivePrice' => $this->formatDecimal($position->stopLossPrice, 6),
            'priceType' => 2,
            'plan_category' => '2',
            'category' => 'limit',
            'size' => (string) $execSize,
            'client_order_id' => $clientOrderId,
        ];

        $this->logger->info('[Position Execution] Placing stop loss order', $payload);
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Stop loss payload', $payload);
        } catch (\Throwable) {}

        $response = $this->tradingProvider->submitTpSlOrder($payload);
        if ((int)($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('Failed to place stop loss order: ' . json_encode($response));
        }

        $payload['order_id'] = $response['data']['order_id'] ?? null;
        $this->orderLifecycle->registerProtectiveOrder($payload, 'STOP_LOSS');

        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Stop loss response', [
                'symbol' => $position->symbol,
                'order_id' => $response['data']['order_id'] ?? null,
                'code' => $response['code'] ?? null,
            ]);
        } catch (\Throwable) {}

        return [
            'order_id' => $response['data']['order_id'] ?? null,
            'client_order_id' => $clientOrderId,
            'type' => 'stop_loss',
            'trigger_price' => $position->stopLossPrice,
            'status' => 'submitted',
            'raw_response' => $response,
        ];
    }

    private function placeTakeProfitOrder(PositionOpeningDto $position, PositionConfigDto $config, ?int $submittedLeverageOverride = null): ?array
    {
        if (!$config->enableTakeProfit) {
            return null;
        }

        $clientOrderId = $this->generateClientOrderId($position->symbol, 'TP');
        $submittedLeverage = $submittedLeverageOverride ?? max(1, (int) round($position->leverage));
        $leverageCalc = max(1.0, (float) $position->leverage);
        $execSize = max(1, (int) floor($position->positionSize * ($submittedLeverage / $leverageCalc)));
        $payload = [
            'symbol' => strtoupper($position->symbol),
            'orderType' => 'take_profit',
            'type' => 'take_profit',
            'side' => $this->mapCloseSide($position->side),
            'triggerPrice' => $this->formatDecimal($position->takeProfitPrice, 6),
            'executivePrice' => $this->formatDecimal($position->takeProfitPrice, 6),
            'priceType' => 2,
            'plan_category' => '2',
            'category' => 'limit',
            'size' => (string) $execSize,
            'client_order_id' => $clientOrderId,
        ];

        $this->logger->info('[Position Execution] Placing take profit order', $payload);
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Take profit payload', $payload);
        } catch (\Throwable) {}

        $response = $this->tradingProvider->submitTpSlOrder($payload);
        if ((int)($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('Failed to place take profit order: ' . json_encode($response));
        }

        $payload['order_id'] = $response['data']['order_id'] ?? null;
        $this->orderLifecycle->registerProtectiveOrder($payload, 'TAKE_PROFIT');

        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Take profit response', [
                'symbol' => $position->symbol,
                'order_id' => $response['data']['order_id'] ?? null,
                'code' => $response['code'] ?? null,
            ]);
        } catch (\Throwable) {}

        return [
            'order_id' => $response['data']['order_id'] ?? null,
            'client_order_id' => $clientOrderId,
            'type' => 'take_profit',
            'trigger_price' => $position->takeProfitPrice,
            'status' => 'submitted',
            'raw_response' => $response,
        ];
    }

    private function simulateExecutionPrice(float $entryPrice, SignalSide $side): float
    {
        // Simulation d'un slippage de ±0.1%
        $slippage = $entryPrice * 0.001;
        $randomFactor = (mt_rand(-100, 100) / 10000); // ±1%
        
        return $entryPrice + ($slippage * $randomFactor);
    }

    private function formatDecimal(float $value, int $precision = 8): string
    {
        $formatted = number_format($value, $precision, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    private function quantizeToTick(string $symbol, float $price): string
    {
        try {
            $contract = $this->contractRepository->findBySymbol(strtoupper($symbol));
            $tick = $contract?->getTickSize();
            $tickF = $tick !== null ? (float) $tick : 0.00001;
            if ($tickF <= 0) {
                $tickF = 0.00001;
            }
            $steps = floor($price / $tickF);
            $q = $steps * $tickF;
            // déduire précision à partir du tick
            $precision = max(0, strlen(substr(strrchr(rtrim((string)$tick, '0'), '.'), 1)) ?: 0);
            return $this->formatDecimal($q, max(0, (int)$precision));
        } catch (\Throwable $e) {
            // Fallback: déduire une précision minimale par tranche de prix en évitant d'arrondir vers le haut
            $precision = 2;
            if ($price < 0.01) {
                $precision = 6;
            } elseif ($price < 0.1) {
                $precision = 5;
            } elseif ($price < 1) {
                $precision = 4;
            } elseif ($price < 10) {
                $precision = 3;
            } else {
                $precision = 2;
            }

            $factor = pow(10, $precision);
            $quantized = floor($price * $factor) / $factor;

            return $this->formatDecimal(max(0.0, $quantized), $precision);
        }
    }

    private function mapOpenSide(SignalSide $side): int
    {
        return match ($side) {
            SignalSide::LONG => 1,
            SignalSide::SHORT => 4,
            default => throw new RuntimeException('Unsupported signal side for open order'),
        };
    }

    private function mapCloseSide(SignalSide $side): int
    {
        return match ($side) {
            SignalSide::LONG => 3,
            SignalSide::SHORT => 2,
            default => throw new RuntimeException('Unsupported signal side for close order'),
        };
    }

    private function generateClientOrderId(string $symbol, string $suffix): string
    {
        return sprintf('MTF_%s_%s_%s', strtoupper($symbol), strtoupper($suffix), bin2hex(random_bytes(4)));
    }
}
