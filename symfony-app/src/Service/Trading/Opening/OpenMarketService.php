<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening;

use App\Service\Bitmart\Private\OrdersService;
use App\Service\Trading\Opening\Config\TradingConfigResolver;
use App\Service\Trading\Opening\DTO\OpenMarketRequest;
use App\Service\Trading\Opening\DTO\OpenMarketResult;
use App\Service\Trading\Opening\Exposure\ActiveExposureGuard;
use App\Service\Trading\Opening\Leverage\LeverageService;
use App\Service\Trading\Opening\Market\MarketSnapshotFactory;
use App\Service\Trading\Opening\Order\MarketOrderBuilder;
use App\Service\Trading\Opening\Order\OrderJournal;
use App\Service\Trading\Opening\Order\OrderScheduler;
use App\Service\Trading\Opening\Sizing\PositionSizer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class OpenMarketService
{
    public function __construct(
        private readonly ActiveExposureGuard $exposureGuard,
        private readonly TradingConfigResolver $configResolver,
        private readonly MarketSnapshotFactory $snapshotFactory,
        private readonly LeverageService $leverageService,
        private readonly PositionSizer $positionSizer,
        private readonly MarketOrderBuilder $orderBuilder,
        private readonly OrdersService $ordersService,
        private readonly OrderScheduler $orderScheduler,
        private readonly OrderJournal $orderJournal,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function open(OpenMarketRequest $request): OpenMarketResult
    {
        $symbol = strtoupper($request->symbol);
        $side = $request->sideLower();

        $this->logger->info('[Opening] Market workflow start', [
            'symbol' => $symbol,
            'side' => $side,
            'timeframe' => $request->timeframe,
            'overrides' => [
                'budget' => $request->budgetOverride,
                'riskAbs' => $request->riskAbsOverride,
                'tpAbs' => $request->tpAbsOverride,
            ],
        ]);

        $orderId = null;

        try {
            $this->exposureGuard->assertNone($symbol, $side);

            $config = $this->configResolver->resolve($request);
            $snapshot = $this->snapshotFactory->create($request, $config);
            $plan = $this->leverageService->plan($symbol, $config, $snapshot);
            $this->leverageService->apply($symbol, $plan, $config->openType);

            $decision = $this->positionSizer->decide($side, $config, $snapshot, $plan);
            $orderDraft = $this->orderBuilder->build($symbol, $side, $config->openType, $decision);

            $response = $this->ordersService->create($orderDraft->payload);
            if (($response['code'] ?? 0) !== 1000) {
                throw new RuntimeException('submit-order error: ' . json_encode($response));
            }

            $orderId = isset($response['data']['order_id']) ? (string) $response['data']['order_id'] : null;

            if ($request->expireAfterSec !== null) {
                $this->orderScheduler->scheduleCancelAll($symbol, $request->expireAfterSec);
            }

            $result = new OpenMarketResult(
                symbol: $symbol,
                side: $side,
                timeframe: $request->timeframe,
                orderId: $orderId,
                entryMark: $snapshot->markPrice,
                stopLoss: $decision->stopLoss,
                takeProfit: $decision->takeProfit,
                contracts: $decision->contracts,
                leverage: $decision->leverage,
                budgetUsed: $config->budgetCapUsdt,
                riskAbsUsd: $config->riskAbsUsdt,
                atr: $snapshot->atr,
            );

            $this->logger->info('[Opening] Market workflow end', [
                'symbol' => $result->symbol,
                'side' => $result->side,
                'order_id' => $result->orderId,
                'contracts' => $result->contracts,
                'leverage' => $result->leverage,
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error('[Opening] Market workflow failed', [
                'symbol' => $symbol,
                'side' => $side,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if ($orderId !== null) {
                $this->orderJournal->record($symbol, $orderId, $side, 'Market');
            }
        }
    }
}
