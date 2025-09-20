<?php

declare(strict_types=1);

namespace App\Service\Scalping;

use App\Service\Risk\PositionSizer;
use App\Service\Trading\OrderPlanner;
use App\Service\Trading\ExchangeClient;
use Psr\Log\LoggerInterface;

/**
 * Service d'exécution appelé après validation 1m (pipeline MTF : 4H/1H contexte, 15m/5m exé).
 */
final class ScalpingExecutor
{
    public function __construct(
        private readonly PositionSizer   $positionSizer,
        private readonly OrderPlanner    $orderPlanner,
        private readonly ExchangeClient  $exchange,
        private readonly LoggerInterface $logger,
        private readonly \App\Service\Trading\PositionRecorder $recorder,
    ) {}

    /**
     * @param 'long'|'short' $side
     * @param array<int, array{high: float, low: float, close: float}> $ohlcExecutionTF
     */
    public function onOneMinuteConfirmed(
        string $symbol,
        string $side,
        float $equity,
        float $riskPct,
        float $entry,
        array $ohlcExecutionTF,
        ?float $liqPrice = null,
        int $atrPeriod = 14,
        string $atrMethod = 'wilder',
        float $atrK = 1.5,
    ): void {
        // 1) Sizing depuis PositionSizer (inclut stop/tp1/lev/guard)
        $sizing = $this->positionSizer->size(
            side: $side,
            equity: $equity,
            riskPct: $riskPct,
            entry: $entry,
            ohlcExecutionTF: $ohlcExecutionTF,
            atrPeriod: $atrPeriod,
            atrMethod: $atrMethod,
            atrK: $atrK,
            liqPrice: $liqPrice
        );

        // 2) Construire le plan d’ordres
        $plan = $this->orderPlanner->buildScalpingPlan(
            symbol: $symbol,
            side: $side,
            entry: $entry,
            qty: $sizing['qty'],
            stop: $sizing['stop'],
            tp1: $sizing['tp1'],
            tp1Portion: 0.60,
            postOnly: true,
            reduceOnly: true
        );

        // 3) Placer l’ordre d’entrée LIMIT post-only (maker)
        $entryOrderId = $this->exchange->placeLimitOrder(
            symbol: $plan->symbol(),
            side: $plan->side(),
            price: $plan->entryPrice(),
            qty: $plan->totalQty(),
            postOnly: $plan->postOnly()
        );
        // Enregistrer PENDING
        $pos = $this->recorder->recordPending(
            exchange: 'bitmart',
            symbol: $plan->symbol(),
            sideUpper: strtoupper($plan->side()), // 'LONG' | 'SHORT'
            entryPrice: $plan->entryPrice(),
            qty: $plan->totalQty(),
            stop: $plan->stopPrice(),
            tp1: $plan->tp1Price(),
            leverage: null, // ou $sizing['leverage'] si tu veux l’écrire dès maintenant
            externalOrderId: $entryOrderId,
            meta: [
                'r_multiple'  => $sizing['r_multiple'],
                'risk_amount' => $sizing['risk_amount'],
            ]
        );

        $this->logger->info('Entry order placed', [
            'symbol' => $plan->symbol(),
            'side' => $plan->side(),
            'qty' => $plan->totalQty(),
            'price' => $plan->entryPrice(),
            'risk_amount' => $sizing['risk_amount'],
            'r' => $sizing['r_multiple'],
        ]);

        // 4) Callback de fill : armer OCO (TP1 + SL) + démarrer trailing sur runner
        $this->exchange->onFilled($entryOrderId, function (float $filledQty) use ($plan, $pos, $sizing) {
            if ($plan->tp1Qty() > 0.0) {
                $this->exchange->placeOco(
                    symbol: $plan->symbol(),
                    side: $plan->side() === 'long' ? 'sell' : 'buy',
                    takeProfitPrice: $plan->tp1Price(),
                    stopPrice: $plan->stopPrice(),
                    qty: min($filledQty, $plan->tp1Qty())
                );
            }
            // Marquer OPEN
            $this->recorder->markOpen(
                pos: $pos,
                entryPrice: $plan->entryPrice(),
                qty: $filledQty,
                leverage: $sizing['leverage'] ?? null
            );

            // Démarrer trailing ATR pour le runner (service/manager non détaillé ici)
            // $this->trailingManager->start($plan->symbol(), $plan->side(), min($filledQty, $plan->runnerQty()));
        });
    }
}

// -----------------------------------------------------------------------------
// Exemple d’appel dans le BON CONTEXTE :
// Quand le pipeline MTF (4H/1H contexte, 15m/5m exé) déclenche le "confirm_1m":
// $executor->onOneMinuteConfirmed(
//     symbol: 'BTCUSDT',
//     side: 'long',
//     equity: 1000.0,
//     riskPct: 2.0,
//     entry: 62000.0,
//     ohlcExecutionTF: $last1mCandles, // array<int, ['high'=>..., 'low'=>..., 'close'=>...]>
//     liqPrice: 48000.0,               // si dispo, sinon null
//     atrPeriod: 14,
//     atrMethod: 'wilder',
//     atrK: 1.5
// );
