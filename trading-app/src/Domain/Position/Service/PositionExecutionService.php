<?php

declare(strict_types=1);

namespace App\Domain\Position\Service;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Position\Dto\PositionOpeningDto;
use App\Domain\Position\Dto\PositionConfigDto;
use App\Domain\Ports\Out\TradingProviderPort;
use App\Domain\Trading\Exposure\ActiveExposureGuard;
use App\Domain\Trading\Order\OrderLifecycleService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PositionExecutionService
{
    public function __construct(
        private readonly TradingProviderPort $tradingProvider,
        private readonly ActiveExposureGuard $exposureGuard,
        private readonly OrderLifecycleService $orderLifecycle,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock
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

            // 2. Configurer le levier
            $this->setLeverage($position, $config);

            // 3. Placer l'ordre principal
            $mainOrder = $this->placeMainOrder($position, $config);

            // 4. Placer l'ordre de stop loss
            $stopLossOrder = $this->placeStopLossOrder($position, $config);

            // 5. Placer l'ordre de take profit
            $takeProfitOrder = $this->placeTakeProfitOrder($position, $config);

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
        // Vérifier la taille minimale d'ordre
        if ($position->positionSize < $config->minOrderSize) {
            throw new \InvalidArgumentException(
                sprintf('Position size %f is below minimum order size %f', $position->positionSize, $config->minOrderSize)
            );
        }

        // Vérifier la taille maximale d'ordre
        if ($position->positionSize > $config->maxOrderSize) {
            throw new \InvalidArgumentException(
                sprintf('Position size %f exceeds maximum order size %f', $position->positionSize, $config->maxOrderSize)
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
    }

    private function setLeverage(PositionOpeningDto $position, PositionConfigDto $config): void
    {
        $leverageInt = max(1, (int) round($position->leverage));
        $openType = $position->executionParams['open_type'] ?? $config->openType;

        $this->logger->info('[Position Execution] Setting leverage', [
            'symbol' => $position->symbol,
            'requested_leverage' => $position->leverage,
            'submitted_leverage' => $leverageInt,
            'open_type' => $openType,
        ]);

        $response = $this->tradingProvider->setLeverage($position->symbol, $leverageInt, $openType);
        if ((int)($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('Failed to set leverage: ' . json_encode($response));
        }

        $this->logger->debug('[Position Execution] Leverage set successfully', [
            'symbol' => $position->symbol,
            'response' => $response,
        ]);
    }

    private function placeMainOrder(PositionOpeningDto $position, PositionConfigDto $config): array
    {
        $clientOrderId = $this->generateClientOrderId($position->symbol, 'OPEN');
        $orderType = strtolower($config->orderType);
        $payload = [
            'symbol' => strtoupper($position->symbol),
            'client_order_id' => $clientOrderId,
            'side' => $this->mapOpenSide($position->side),
            'mode' => 1,
            'type' => $orderType,
            'open_type' => $config->openType,
            'size' => $this->formatDecimal($position->positionSize, 6),
            'leverage' => (string) max(1, (int) round($position->leverage)),
        ];

        if ($orderType !== 'market') {
            $payload['price'] = $this->formatDecimal($position->entryPrice, 6);
            $payload['time_in_force'] = strtolower($config->timeInForce);
        }

        if (!$config->enablePartialFills) {
            $payload['allow_partial'] = 0;
        }

        $this->logger->info('[Position Execution] Placing main order', $payload);

        $response = $this->tradingProvider->submitOrder($payload);
        if ((int)($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('Failed to place main order: ' . json_encode($response));
        }

        $orderId = $response['data']['order_id'] ?? null;

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

    private function placeStopLossOrder(PositionOpeningDto $position, PositionConfigDto $config): ?array
    {
        if (!$config->enableStopLoss) {
            return null;
        }

        $clientOrderId = $this->generateClientOrderId($position->symbol, 'SL');
        $payload = [
            'symbol' => strtoupper($position->symbol),
            'orderType' => 'stop_loss',
            'side' => $this->mapCloseSide($position->side),
            'triggerPrice' => $this->formatDecimal($position->stopLossPrice, 6),
            'executivePrice' => $this->formatDecimal($position->stopLossPrice, 6),
            'priceType' => -1,
            'plan_category' => -2,
            'category' => 'market',
            'size' => $this->formatDecimal($position->positionSize, 6),
            'client_order_id' => $clientOrderId,
        ];

        $this->logger->info('[Position Execution] Placing stop loss order', $payload);

        $response = $this->tradingProvider->submitTpSlOrder($payload);
        if ((int)($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('Failed to place stop loss order: ' . json_encode($response));
        }

        $payload['order_id'] = $response['data']['order_id'] ?? null;
        $this->orderLifecycle->registerProtectiveOrder($payload, 'STOP_LOSS');

        return [
            'order_id' => $response['data']['order_id'] ?? null,
            'client_order_id' => $clientOrderId,
            'type' => 'stop_loss',
            'trigger_price' => $position->stopLossPrice,
            'status' => 'submitted',
            'raw_response' => $response,
        ];
    }

    private function placeTakeProfitOrder(PositionOpeningDto $position, PositionConfigDto $config): ?array
    {
        if (!$config->enableTakeProfit) {
            return null;
        }

        $clientOrderId = $this->generateClientOrderId($position->symbol, 'TP');
        $payload = [
            'symbol' => strtoupper($position->symbol),
            'orderType' => 'take_profit',
            'side' => $this->mapCloseSide($position->side),
            'triggerPrice' => $this->formatDecimal($position->takeProfitPrice, 6),
            'executivePrice' => $this->formatDecimal($position->takeProfitPrice, 6),
            'priceType' => -1,
            'plan_category' => -2,
            'category' => 'market',
            'size' => $this->formatDecimal($position->positionSize, 6),
            'client_order_id' => $clientOrderId,
        ];

        $this->logger->info('[Position Execution] Placing take profit order', $payload);

        $response = $this->tradingProvider->submitTpSlOrder($payload);
        if ((int)($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('Failed to place take profit order: ' . json_encode($response));
        }

        $payload['order_id'] = $response['data']['order_id'] ?? null;
        $this->orderLifecycle->registerProtectiveOrder($payload, 'TAKE_PROFIT');

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
