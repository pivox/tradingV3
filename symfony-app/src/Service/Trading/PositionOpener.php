<?php

namespace App\Service\Trading;

use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Repository\RuntimeGuardRepository;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\AtrCalculator;
use App\Service\Risk\PositionSizer;
use Psr\Log\LoggerInterface;

class PositionOpener
{
    public function __construct(
        private readonly OrderPlanner $orderPlanner,
        private readonly PositionRecorder $recorder,
        private readonly TradingPort $trading,
        private readonly PositionSizer $sizer,
        private readonly AtrCalculator $atr,
        private readonly KlineRepository $klines,
        private readonly LoggerInterface $logger,
        private readonly RuntimeGuardRepository $runtimeGuardRepository,
        private readonly ContractRepository $contractRepository,
        private readonly TradingParameters $tradingParameters,
        private readonly LoggerInterface $positionsLogger,
    ) {}

    public function open(string $symbol, string $finalSideUpper, string $timeframe, array $tfSignal): array
    {
        $cfg = $this->tradingParameters->getConfig();

        // --- RISK depuis conf ---
        $riskPct = (float)($cfg['risk']['fixed_risk_pct'] ?? 5.0) / 100.0;
        $dailyMaxLossPct = (float)($cfg['risk']['daily_max_loss_pct'] ?? 6.0) / 100.0;
        $maxPositions = (int)($cfg['risk']['max_concurrent_positions'] ?? 4);

        // --- ATR depuis conf ---
        $atrPeriod = (int)($cfg['atr']['period'] ?? 14);
        $atrMethod = (string)($cfg['atr']['method'] ?? 'wilder');  // <<<<<<
        $kAtr = (float)($cfg['atr']['sl_multiplier'] ?? 1.5);
        $trailMult = $cfg['atr']['trailing_multiplier'] ?? null;   // null ou float
        $trailAfterTp1 = (bool)($cfg['atr']['trail_after_tp1'] ?? true);
        $trailStepPct = (float)($cfg['atr']['trail_step_pct'] ?? 0.10);

        $atrTf = (string)($cfg['atr']['tf'] ?? '15m');

        // --- MTF (validation simple du TF courant) ---
        $mtfExec = array_map('strtolower', (array)($cfg['mtf']['execution'] ?? ['15m', '5m', '1m']));
        if (!in_array(strtolower($timeframe), $mtfExec, true)) {
            throw new \RuntimeException("TF $timeframe non autorisé par la conf (execution=" . implode(',', $mtfExec) . ")");
        }

        // --- ORDER PLAN / ROUTING ---
        $routingPrefer   = strtolower((string)($cfg['order_plan']['routing']['prefer'] ?? 'limit'));     // limit|market
        $routingFallback = strtolower((string)($cfg['order_plan']['routing']['fallback'] ?? 'market'));  // limit|market
        $postOnly        = (bool)($cfg['order_plan']['routing']['post_only'] ?? true);
        $slipMaxBps      = (int)($cfg['order_plan']['routing']['slippage_bps_max'] ?? 5); // 5 bps = 0.05%

        // --- TP SPLIT (déjà lu ailleurs, mais on s’aligne sur le YAML) ---
        $tp1Portion = (float)( ($cfg['order_plan']['brackets']['tp_split']['tp1_size_pct'] ?? 60) / 100.0 );

        // --- TIME-STOP ---
        $timeStopEnabled  = (bool)($cfg['order_plan']['time_stop']['enabled'] ?? true);
        $timeStopMaxMin   = (int)($cfg['order_plan']['time_stop']['max_minutes'] ?? 90);
        $timeStopMinProgR = (float)($cfg['order_plan']['time_stop']['min_progress_r'] ?? 0.5);

        // === Prix & ATR ===
        $isLong     = ($finalSideUpper === 'LONG');
        $entryPrice = $this->resolveEntryPrice($symbol, $tfSignal, $timeframe);
        $atrTf      = (string)($cfg['atr']['tf'] ?? '15m');
        $atrMethod  = (string)($cfg['atr']['method'] ?? 'wilder');
        $atrPeriod  = (int)  ($cfg['atr']['period'] ?? 14);
        $kAtr       = (float)($cfg['atr']['sl_multiplier'] ?? 1.5);
        $atrVal     = $this->computeAtr($symbol, $atrTf, $atrPeriod, $atrMethod);

        // === Sizing (comme avant) ===
        $riskPct         = (float)($cfg['risk']['fixed_risk_pct'] ?? 5.0) / 100.0;
        $liqGuardMin     = (float)($cfg['liquidation_guard']['min_ratio'] ?? 3.0);
        $fallbackMaxLev  = (int)($cfg['liquidation_guard']['fallback_max_leverage'] ?? 10);
        $s = $this->sizer->size($entryPrice, $atrVal, $riskPct, $kAtr, true, $fallbackMaxLev);
        $qty     = (float)$s['qty'];
        $stopPct = (float)$s['stop_pct'];
        $R       = $entryPrice * $stopPct;

        $stopPrice = $isLong ? $entryPrice - $R : $entryPrice + $R;
        $tp1R      = (float)($cfg['long']['take_profit']['tp1_r'] ?? $cfg['short']['take_profit']['tp1_r'] ?? 2.0);
        $tp1Price  = $isLong ? $entryPrice + $tp1R * $R : $entryPrice - $tp1R * $R;

        // === Liq guard ===
        if (!$this->trading->passesLiquidationGuard($symbol, $isLong ? 'long' : 'short', $entryPrice, $stopPrice, $liqGuardMin)) {
            throw new \RuntimeException("Liquidation guard failed for $symbol (min ratio={$liqGuardMin}x)");
        }
        $this->positionsLogger->info('PositionOpener: buildScalpingPlan', [
            'symbol'    => $symbol,
            'side'      => $finalSideUpper,
            'entry'     => $entryPrice,
            'stop'      => $stopPrice,
            'tp1'       => $tp1Price,
            'qty'       => $qty,
            'R_usdt'    => $R,
            'atr_tf'    => $atrTf,
            'atr_period'=> $atrPeriod,
            'atr'       => $atrVal,
            'stop_pct'  => $stopPct,
            'risk_pct'  => $riskPct,
            'leverage'  => $s['leverage'],
            'max_positions' => $maxPositions,
        ]);

        // === Plan (quantization inside) ===
        $plan = $this->orderPlanner->buildScalpingPlan(
            symbol: $symbol,
            side:   $isLong ? 'long' : 'short',
            entry:  $entryPrice,
            qty:    $qty,
            stop:   $stopPrice,
            tp1:    $tp1Price,
            tp1Portion: $tp1Portion,
            postOnly:   ($routingPrefer === 'limit') ? $postOnly : false, // postOnly utile que sur LIMIT
            reduceOnly: true,
            meta: [
                'tf'           => $timeframe,
                'risk_pct'     => $riskPct,
                'atr_tf'       => $atrTf,
                'atr_period'   => $atrPeriod,
                'atr_method'   => $atrMethod,
                'atr'          => $atrVal,
                'stop_pct'     => $stopPct,
                'tp1RMultiple' => $tp1R,
                // time-stop meta pour l’exécuteur
                'time_stop'    => [
                    'enabled'       => $timeStopEnabled,
                    'max_minutes'   => $timeStopMaxMin,
                    'min_progress_r'=> $timeStopMinProgR,
                ],
                // routing meta
                'routing' => [
                    'prefer'  => $routingPrefer,
                    'fallback'=> $routingFallback,
                    'post_only'=> $postOnly,
                    'slip_bps_max'=> $slipMaxBps,
                ],
            ]
        );

        // === Choix LIMIT vs MARKET selon slippage ===
        $orderType  = $routingPrefer; // 'limit' ou 'market'
        if ($slipMaxBps > 0) {
            $lastPx = $this->trading->getLastPrice($symbol); // ou un autre feed fiable
            $slipBps = $this->bps($lastPx, $plan->entryPrice());
            $badSlip = ($isLong && $lastPx > $plan->entryPrice()) || (!$isLong && $lastPx < $plan->entryPrice());
            if ($badSlip && $slipBps > $slipMaxBps && $routingFallback === 'market') {
                $orderType = 'market'; // on bascule si dépassement du seuil dans le mauvais sens
            }
        }

        // === Payload vers TradingPort ===
        $payload = [
            'symbol'   => $plan->symbol(),
            'side'     => strtoupper($finalSideUpper),
            'postOnly' => $plan->postOnly() && $orderType === 'limit',
            'entry'    => [
                'type'     => $orderType, // 'limit'|'market'
                'price'    => $plan->entryPrice(), // ignoré si market par l’adapter
                'quantity' => $plan->totalQty(),
            ],
            'legs'     => [
                'tp1' => ['price' => $plan->tp1Price(), 'quantity' => $plan->tp1Qty(), 'reduceOnly' => true],
                'sl'  => ['stopPrice' => $plan->stopPrice(), 'quantity' => $plan->totalQty(), 'reduceOnly' => true],
            ],
            'runner'   => [
                'enabled'  => ($cfg['atr']['trail_after_tp1'] ?? true) && $plan->runnerQty() > 0.0,
                'quantity' => $plan->runnerQty(),
                'trailing' => [
                    'type'     => 'atr',
                    'mult'     => $cfg['atr']['trailing_multiplier'] ?? null,
                    'step_pct' => (float)($cfg['atr']['trail_step_pct'] ?? 0.10),
                    'sourceTf' => '1m',
                ],
            ],
            'routing'  => [
                'prefer'     => $routingPrefer,
                'fallback'   => $routingFallback,
                'slip_bps'   => $slipMaxBps,
                'decided'    => $orderType,
            ],
        ];

        $placed = $this->trading->placeOrderPlan($payload);

        // === Persist (identique) ===
        $contract = $this->contractRepository->find($symbol)
            ?? throw new \RuntimeException("Contract introuvable pour le symbole $symbol");

        $amountUsdt = $plan->entryPrice() * $plan->totalQty();
        $position = $this->recorder->recordPending(
            contract:        $contract,
            sideUpper:       $finalSideUpper,
            entryPrice:      $plan->entryPrice(),
            qtyContract:     $plan->totalQty(),
            stopPrice:       $plan->stopPrice(),
            tp1Price:        $plan->tp1Price(),
            leverage:        (float)($s['leverage'] ?? 1.0),
            externalOrderId: $placed['entry']['order_id'] ?? null,
            amountUsdt:      $amountUsdt,
            meta: $payload['routing'] + [
                'tp1Qty'        => $plan->tp1Qty(),
                'runnerQty'     => $plan->runnerQty(),
                'risk_pct'      => $riskPct,
                'stop_pct'      => $stopPct,
                'atr'           => $atrVal,
                'atr_tf'        => $atrTf,
                'atr_period'    => $atrPeriod,
                'cfg'           => $cfg['meta']['name'] ?? 'SCALPING',
                'time_stop'     => $plan->meta()['time_stop'] ?? null,
            ]
        );

        $this->runtimeGuardRepository->pause();

        $out = [
            'placed'      => $placed,
            'leverage'    => $s['leverage'],
            'stop_pct'    => $stopPct,
            'entry'       => $plan->entryPrice(),
            'stop'        => $plan->stopPrice(),
            'tp1'         => $plan->tp1Price(),
            'qty'         => $plan->totalQty(),
            'position_id' => $position->getId(),
        ];
        $this->logger->info('PositionOpener: order plan placed & position pending', $out + [
                'symbol' => $symbol, 'side' => $finalSideUpper, 'tf' => $timeframe
            ]);
        return $out;
    }

    private function computeAtr(string $symbol, string $atrTf, int $atrPeriod, string $method = 'wilder'): float
    {
        $lookback = max(3 * $atrPeriod + 5, 60);
        $candles  = $this->klines->findRecentBySymbolAndTimeframe($symbol, $atrTf, $lookback);
        if (count($candles) <= $atrPeriod) {
            throw new \RuntimeException("Not enough candles for ATR: got ".count($candles)." need > $atrPeriod");
        }
        $ohlc = [];
        foreach ($candles as $k) {
            $ohlc[] = ['high'=>(float)$k->getHigh(),'low'=>(float)$k->getLow(),'close'=>(float)$k->getClose()];
        }
        $atr = (float)$this->atr->compute($ohlc, $atrPeriod, $method); // <<<<<<
        if ($atr <= 0.0 || !is_finite($atr)) {
            throw new \RuntimeException("ATR compute invalid for $symbol on $atrTf");
        }
        return $atr;
    }


    private function resolveEntryPrice(string $symbol, array $tfSignal, string $tfFallback = '1m'): float
    {
        $val = (float)($tfSignal['close'] ?? 0.0);
        if ($val > 0.0 && is_finite($val)) return $val;
        foreach ([$tfFallback, '5m'] as $tf) {
            $c = $this->klines->findRecentBySymbolAndTimeframe($symbol, $tf, 1)[0] ?? null;
            if ($c && method_exists($c, 'getClose')) $val = (float)$c->getClose();
            elseif (is_array($c) && isset($c['close'])) $val = (float)$c['close'];
            if ($val > 0.0 && is_finite($val)) return $val;
        }
        throw new \RuntimeException("resolveEntryPrice: impossible de récupérer un prix > 0 pour {$symbol}");
    }
}
