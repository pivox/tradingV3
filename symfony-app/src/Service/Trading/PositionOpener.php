<?php
declare(strict_types=1);

namespace App\Service\Trading;

use App\Repository\KlineRepository;
use App\Service\Indicator\AtrCalculator;
use Psr\Log\LoggerInterface;

final class PositionOpener
{
    public function __construct(
        private readonly OrderPlanner     $orderPlanner,
        private readonly PositionRecorder $recorder,
        private readonly TradingService   $tradingService,
        private readonly AtrCalculator    $atrCalculator,
        private readonly KlineRepository  $klineRepository,
        private readonly LoggerInterface  $logger,
    ) {}

    /**
     * @param non-empty-string $symbol
     * @param 'LONG'|'SHORT'   $finalSideUpper
     * @param non-empty-string $timeframe      TF courant (ex: '15m','5m','1m') pour la traçabilité
     * @param array{close?: float} $tfSignal   Bloc signal du TF courant (au moins close)
     *
     * @return array{
     *   placed: array,
     *   leverage: float,
     *   stop_pct: float,
     *   entry: float,
     *   stop: float,
     *   tp1: float,
     *   qty: float,
     *   position_id?: int
     * }
     */
    public function open(
        string $symbol,
        string $finalSideUpper,
        string $timeframe,
        array  $tfSignal,
        float  $riskPct         = 0.02,   // 2%
        int    $atrPeriod       = 14,
        string $atrTf           = '15m',  // TF pour le calcul ATR
        float  $kAtr            = 1.0,    // multiplicateur ATR pour le stop
        float  $tp1RMultiple    = 2.0,    // TP1 à 2R
        float  $tp1Portion      = 0.60,   // 60% TP1 / 40% runner
        float  $liqGuardMin     = 3.0     // liquidation guard min ratio
    ): array {
        $finalSideUpper = strtoupper($finalSideUpper);
        if (!in_array($finalSideUpper, ['LONG','SHORT'], true)) {
            throw new \InvalidArgumentException('finalSideUpper must be LONG|SHORT');
        }
        $sidePlanner = $finalSideUpper === 'LONG' ? 'long' : 'short';

        // a) Prix d’entrée depuis le TF courant
        $entryPrice = (float)($tfSignal['close'] ?? 0.0);
        if ($entryPrice <= 0.0 || !is_finite($entryPrice)) {
            throw new \RuntimeException('Entry price missing or invalid in $tfSignal[close]');
        }

        // b) Charger des bougies pour calculer l’ATR
        // NB: l’ATR a besoin d’au moins $atrPeriod+1 TR, on prend une marge (ex. 3×period)
        $lookback = max(3 * $atrPeriod + 5, 60);
        $candles = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, $atrTf, $lookback);
        if (count($candles) <= $atrPeriod) {
            throw new \RuntimeException("Not enough candles for ATR: got ".count($candles)." need > $atrPeriod");
        }

        // c) Construire l’array OHLC attendu par AtrCalculator::compute()
        $ohlc = [];
        foreach ($candles as $k) {
            // Adapté à ton entity Kline (getHigh/getLow/getClose)
            $ohlc[] = [
                'high'  => (float)$k->getHigh(),
                'low'   => (float)$k->getLow(),
                'close' => (float)$k->getClose(),
            ];
        }

        // d) Calcul de l’ATR (Wilder par défaut)
        $atr = (float)$this->atrCalculator->compute($ohlc, $atrPeriod, 'wilder');
        if ($atr <= 0.0 || !is_finite($atr)) {
            throw new \RuntimeException("ATR compute returned invalid value for $symbol on $atrTf");
        }

        // e) stop_pct / levier
        $stopPct  = ($kAtr * $atr) / $entryPrice;        // fraction (0.02 = 2%)
        if ($stopPct <= 0.0) {
            throw new \RuntimeException('Computed stop_pct <= 0, check ATR/price');
        }
        $leverage = $riskPct / $stopPct;                 // levier dérivé

        // f) Taille de position (risk-based)
        $equity      = (float)$this->tradingService->getEquity();
        if ($equity <= 0.0) {
            throw new \RuntimeException('Equity <= 0, cannot size position');
        }
        $riskUSDT    = $equity * $riskPct;
        $riskPerUnit = $entryPrice * $stopPct;
        if ($riskPerUnit <= 0.0) {
            throw new \RuntimeException('Risk per unit <= 0, invalid stop distance');
        }
        $qty = $riskUSDT / $riskPerUnit;

        // g) SL / TP1 en multiples de R
        $R = $entryPrice * $stopPct;
        if ($finalSideUpper === 'LONG') {
            $stopPrice = $entryPrice - $R;
            $tp1Price  = $entryPrice + $tp1RMultiple * $R;
        } else {
            $stopPrice = $entryPrice + $R;
            $tp1Price  = $entryPrice - $tp1RMultiple * $R;
        }

        // h) Liquidation guard
        if (!$this->tradingService->passesLiquidationGuard($symbol, $sidePlanner, $entryPrice, $stopPrice, $liqGuardMin)) {
            throw new \RuntimeException("Liquidation guard failed for $symbol (min ratio={$liqGuardMin}x)");
        }

        // i) Construire le plan d’ordres (quantization via OrderPlanner)
        $plan = $this->orderPlanner->buildScalpingPlan(
            symbol: $symbol,
            side:   $sidePlanner,      // 'long' | 'short'
            entry:  $entryPrice,
            qty:    $qty,
            stop:   $stopPrice,
            tp1:    $tp1Price,
            tp1Portion: $tp1Portion,
            postOnly:   true,
            reduceOnly: true
        );

        // j) Placer le plan
        $placed = $this->tradingService->placeOrderPlan([
            'symbol'     => $plan->symbol(),
            'side'       => $plan->side(),
            'entry'      => $plan->entryPrice(),
            'qty'        => $plan->totalQty(),
            'tp1'        => $plan->tp1Price(),
            'stop'       => $plan->stopPrice(),
            'tp1Qty'     => $plan->tp1Qty(),
            'runnerQty'  => $plan->runnerQty(),
            'postOnly'   => $plan->postOnly(),
            'reduceOnly' => $plan->reduceOnly(),
            'meta'       => [
                'tf'           => $timeframe,
                'risk_pct'     => $riskPct,
                'atr_tf'       => $atrTf,
                'atr_period'   => $atrPeriod,
                'atr'          => $atr,
                'stop_pct'     => $stopPct,
                'leverage'     => $leverage,
                'tp1RMultiple' => $tp1RMultiple,
                'tp1Portion'   => $tp1Portion,
            ],
        ]);

        // k) Enregistrement DB (PENDING)
        $position = $this->recorder->recordPending(
            exchange:        'bitmart',
            symbol:          $symbol,
            sideUpper:       $finalSideUpper,
            entryPrice:      $plan->entryPrice(),
            qty:             $plan->totalQty(),
            stop:            $plan->stopPrice(),
            tp1:             $plan->tp1Price(),
            leverage:        $leverage,
            externalOrderId: $placed['orderId'] ?? null,
            meta: [
                'tp1Qty'     => $plan->tp1Qty(),
                'runnerQty'  => $plan->runnerQty(),
                'mock'       => $placed['mock'] ?? null,
            ]
        );

        $payload = [
            'placed'      => $placed,
            'leverage'    => $leverage,
            'stop_pct'    => $stopPct,
            'entry'       => $entryPrice,
            'stop'        => $stopPrice,
            'tp1'         => $tp1Price,
            'qty'         => $qty,
            'position_id' => $position->getId(),
        ];

        $this->logger->info('PositionOpener: order plan placed & position pending', $payload + [
                'symbol'   => $symbol,
                'side'     => $finalSideUpper,
                'tf'       => $timeframe,
            ]);

        return $payload;
    }
}
