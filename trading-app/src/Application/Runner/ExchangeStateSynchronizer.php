<?php

declare(strict_types=1);

namespace App\Application\Runner;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\PositionDto;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Entity\Position;
use App\Provider\Context\ExchangeContext;
use App\Repository\PositionRepository;
use Psr\Log\LoggerInterface;
use App\Trading\Lineage\LineageContextException;
use App\Trading\Lineage\Persistence\CanonicalPositionRecoveryService;

final class ExchangeStateSynchronizer
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly PositionRepository $positionRepository,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $positionsLogger,
        private readonly ?CanonicalPositionRecoveryService $canonicalPositionRecovery = null,
    ) {
    }

    /**
     * Synchronise les tables depuis l'exchange.
     *
     * @return array{open_positions: array<int, mixed>, open_orders: array<int, mixed>}
     */
    public function sync(ExchangeContext $context): array
    {
        $openPositions = [];
        $openOrders = [];

        try {
            $provider = $this->mainProvider->forContext($context);
            $accountProvider = $provider->getAccountProvider();
            $orderProvider = $provider->getOrderProvider();

            if (!$accountProvider && !$orderProvider) {
                $this->positionsLogger->warning('[MTF Runner] Cannot sync tables: missing providers');

                return [
                    'open_positions' => $openPositions,
                    'open_orders' => $openOrders,
                ];
            }

            if ($accountProvider) {
                $openPositions = $this->syncPositions($accountProvider, $context);
            }

            if ($orderProvider) {
                $openOrders = $this->syncOrders($orderProvider);
            }
        } catch (LineageContextException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to sync tables', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return [
            'open_positions' => $openPositions,
            'open_orders' => $openOrders,
        ];
    }

    /**
     * Synchronise les positions depuis l'exchange vers la table positions.
     *
     * @return array<int, mixed>
     */
    private function syncPositions(AccountProviderInterface $accountProvider, ExchangeContext $context): array
    {
        $openPositions = [];

        try {
            /** @var array<int, PositionDto> $openPositions */
            $openPositions = $accountProvider->getOpenPositions();
            $this->positionsLogger->info('[MTF Runner] Syncing positions', [
                'count' => count($openPositions),
            ]);

            foreach ($openPositions as $positionDto) {
                try {
                    $symbol = strtoupper($positionDto->symbol);
                    $side = strtoupper($positionDto->side->value);

                    $evidence = $positionDto->canonicalEvidence();
                    $position = $evidence->exchangePositionId !== null
                        ? $this->positionRepository->findOneByCanonicalExchangePositionId($evidence->exchangePositionId, $context)
                        : $this->positionRepository->findOneBySymbolSide($symbol, $side, $context);
                    if (!$position) {
                        $position = new Position($symbol, $side, $context->exchange, $context->marketType);
                    }

                    if ($position->getSymbol() !== $symbol
                        || $position->getSide() !== $side
                        || $position->getExchange() !== $context->exchange->value
                        || $position->getMarketType() !== $context->marketType->value
                    ) {
                        throw new LineageContextException('canonical_identity_mismatch:position_boundary');
                    }
                    $classification = $position->lineageClassification();
                    if ($classification === 'incomplete') {
                        throw new LineageContextException('canonical_identity_incomplete:position');
                    }
                    $predecessor = $this->canonicalPositionRecovery?->resolve($evidence, $context);
                    if ($classification === 'canonical') {
                        $stored = $position->requireLineageContext();
                        if ($evidence->exchangePositionId === null
                            || $predecessor === null
                            || $predecessor->context->toArray() !== $stored->toArray()
                            || $predecessor->exchangePositionId !== $position->getCanonicalExchangePositionId()
                        ) {
                            throw new LineageContextException('canonical_identity_mismatch:position_predecessor');
                        }
                    } elseif ($predecessor !== null) {
                        if (!\in_array($predecessor->order->getSide(), [1, 4], true)) {
                            throw new LineageContextException('canonical_identity_invalid:position_opening_order');
                        }
                        $position->applyCanonicalPredecessor($predecessor);
                    }

                    $position->setSize($positionDto->size->__toString());
                    $position->setAvgEntryPrice($positionDto->entryPrice->__toString());
                    $position->setLeverage((int) $positionDto->leverage->__toString());
                    $position->setUnrealizedPnl($positionDto->unrealizedPnl->__toString());
                    $position->setStatus('OPEN');
                    $position->mergePayload([
                        'exchange' => $context->exchange->value,
                        'market_type' => $context->marketType->value,
                        'mark_price' => $positionDto->markPrice->__toString(),
                        'margin' => $positionDto->margin->__toString(),
                        'realized_pnl' => $positionDto->realizedPnl->__toString(),
                    ]);

                    $this->positionRepository->upsert($position);
                } catch (LineageContextException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $this->logger->error('[MTF Runner] Failed to sync position', [
                        'symbol' => $positionDto->symbol ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (LineageContextException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to sync positions', [
                'error' => $e->getMessage(),
            ]);
        }

        return $openPositions;
    }

    /**
     * Synchronise les ordres depuis l'exchange vers futures_order et futures_order_trade.
     *
     * @return array<int, mixed>
     */
    private function syncOrders(OrderProviderInterface $orderProvider): array
    {
        $openOrders = [];

        try {
            $openOrders = $orderProvider->getOpenOrders();
            $this->positionsLogger->info('[MTF Runner] Syncing orders via provider', [
                'count' => count($openOrders),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Runner] Failed to sync orders', [
                'error' => $e->getMessage(),
            ]);
        }

        return $openOrders;
    }
}
