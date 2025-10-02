<?php
declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\AtrCalculator;
use App\Util\SrRiskHelper;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;
use RuntimeException;
use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Service\Bitmart\Private\OrdersService;
use App\Service\Bitmart\Private\PositionsService as BitmartPositionsService;
use App\Service\Bitmart\Private\TrailOrdersService;

final class PositionOpener
{

    // --- Constantes spécifiques High Conviction ---
    private const HC_DEFAULT_LEV_CAP     = 50;   // levier max autorisé par la stratégie HC
    private const HC_MIN_LIQ_RATIO       = 3.0;  // liquidation ≥ 3x distance SL (si tu veux contrôler ici)
    private const HC_DEFAULT_R_MULTIPLE  = 2.0;  // TP à 2R (cohérent YAML v1.2)
    private const HC_DEFAULT_EXPIRE_SEC  = 120;  // annulation auto (2 minutes)

    public function __construct(
        private readonly AtrCalculator $atrCalculator,
        private readonly TradingParameters $tradingParameters,
        private readonly LoggerInterface $positionsLogger, // channel "positions"
        private readonly ContractPipelineRepository $pipelineRepository,
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
        private readonly OrdersService $ordersService,
        private readonly BitmartPositionsService $bitmartPositions,
        private readonly TrailOrdersService $trailOrders,
        private readonly BitmartHttpClientPublic $bitmartPublic,
    ) {}

    /**
     * Wrapper "standard" : utilise TradingParameters (pas d'override).
     */
    public function openMarketWithTpSl(
        string $symbol,
        string $finalSideUpper,   // 'LONG'|'SHORT'
        string $timeframe,
        array $tfSignal = [],
        array $ohlc = [],
        ?int $expireAfterSec = null
    ): array {
        return $this->doOpenWithOverrides(
            symbol: $symbol,
            finalSideUpper: $finalSideUpper,
            timeframe: $timeframe,
            tfSignal: $tfSignal,
            ohlc: $ohlc,
            expireAfterSec: $expireAfterSec
        );
    }

    /**
     * Variante demandée : budget = 50 USDT, risque absolu = 7 USDT (TP absolu = cfg).
     */
    public function openMarketWithTpSl50x7(
        string $symbol,
        string $finalSideUpper,   // 'LONG'|'SHORT'
        string $timeframe,
        array $tfSignal = [],
        array $ohlc = [],
    ): array {
        return $this->doOpenWithOverrides(
            symbol: $symbol,
            finalSideUpper: $finalSideUpper,
            timeframe: $timeframe,
            tfSignal: $tfSignal,
            ohlc: $ohlc,
            budgetOverride: 50.0,
            riskAbsOverride: 7.0
        );
    }

    public function openLimitWithTpSlPct(
        string $symbol,
        string $finalSideUpper,    // 'LONG' | 'SHORT'
        float $marginUsdt = 5.0,   // même défauts que ta commande
        int   $leverage   = 1,
        float $slPct      = 0.05,  // 5%
        float $tpPct      = 0.10,  // 10%
        string $timeframe = 'manual', // optionnel: pour logs
        array $meta       = [],        // optionnel: pour tracer signal,etc.
        ?int  $expireAfterSec = null
    ): array {
        return $this->doOpenLimitWithPct(
            symbol: $symbol,
            finalSideUpper: $finalSideUpper,
            marginUsdt: $marginUsdt,
            leverage: $leverage,
            slPct: $slPct,
            tpPct: $tpPct,
            timeframe: $timeframe,
            meta: $meta
        );
    }


    /**
     * Coeur factorisé : ouvre un market order avec TP/SL absolus (en USDT).
     * Permet d’overrider budget / risque_abs / tp_abs au besoin.
     */
    private function doOpenWithOverrides(
        string $symbol,
        string $finalSideUpper,   // 'LONG'|'SHORT'
        string $timeframe,
        array $tfSignal = [],
        array $ohlc = [],
        ?float $budgetOverride = null,
        ?float $riskAbsOverride = null,
        ?float $tpAbsOverride = null,
        ?int   $expireAfterSec = null
    ): array {
        $side = strtolower($finalSideUpper); // 'long'|'short'
        $this->positionsLogger->info('===============================');
        $this->positionsLogger->info('=== PositionOpener:start ===', [
            'symbol'     => $symbol,
            'side'       => $side,
            'timeframe'  => $timeframe,
            'signal'     => $tfSignal,
            'ohlc_count' => \count($ohlc),
            'overrides'  => [
                'budget'  => $budgetOverride,
                'riskAbs' => $riskAbsOverride,
                'tpAbs'   => $tpAbsOverride,
            ],
        ]);

        try {
            /* ------------------ 1) Config ------------------ */
            $cfg = $this->tradingParameters->all();
            $this->positionsLogger->info('Loaded trading config', $cfg);

            $budgetCapUsdt = $budgetOverride ?? (float)($cfg['budget']['open_cap_usdt'] ?? 50.0);
            $riskAbsUsdt   = $riskAbsOverride ?? (float)($cfg['risk']['abs_usdt'] ?? 3.0);
            $tpAbsUsdt     = $tpAbsOverride   ?? (float)($cfg['tp']['abs_usdt'] ?? 5.0);

            $riskPct       = (float)($cfg['risk']['pct'] ?? 0.02);
            $atrLookback   = (int)  ($cfg['atr']['lookback'] ?? 14);
            $atrMethod     = (string)($cfg['atr']['method'] ?? 'wilder');
            $atrTimeframe  = (string)($cfg['atr']['timeframe'] ?? '15m');
            $atrKStop      = (float)($cfg['atr']['k_stop'] ?? 1.5);
            $tpRMultiple   = (float)($cfg['tp']['r_multiple'] ?? 2.0);
            $openType      = (string)($cfg['margin']['open_type'] ?? 'isolated');

            if (\count($ohlc) === 0) {
                $needed = max($atrLookback + 1, 120);
                $ohlc = $this->klineRepository->findLastKlines(
                    symbol: $symbol,
                    timeframe: $atrTimeframe,
                    limit: $needed
                );
                $this->positionsLogger->info('ATR OHLC source loaded', [
                    'timeframe' => $atrTimeframe,
                    'requested' => $needed,
                    'actual'    => \count($ohlc)
                ]);
            }

            if (\count($ohlc) <= $atrLookback) {
                throw new InvalidArgumentException("OHLC insuffisant pour ATR (tf=$atrTimeframe, lookback=$atrLookback)");
            }

            /* ------------------ 2) Contract details ------------------ */
            $details = $this->getContractDetails($symbol);
            $this->positionsLogger->info('Contract details loaded', $details);

            $status = (string)($details['status'] ?? 'Trading');
            if ($status !== 'Trading') {
                throw new RuntimeException("Symbol status is '$status' (not Trading)");
            }

            $tick     = (float)($details['price_precision'] ?? 0.0);
            $qtyStep  = (float)($details['vol_precision']   ?? 0.0);
            $ctSize   = (float)($details['contract_size']   ?? 0.0);
            $minVol   = (int)  ($details['min_volume']      ?? 1);
            $maxVol   = (int)  ($details['max_volume']      ?? PHP_INT_MAX);
            $maxLev   = (int)  ($details['max_leverage']    ?? 50);
            $marketCap = isset($details['market_max_volume']) && (int)$details['market_max_volume'] > 0
                ? (int)$details['market_max_volume'] : null;

            if ($tick <= 0 || $qtyStep <= 0 || $ctSize <= 0 || $minVol <= 0) {
                throw new RuntimeException("Invalid contract details: tick=$tick qtyStep=$qtyStep ctSize=$ctSize minVol=$minVol");
            }

            /* ------------------ 3) Mark price ------------------ */
            $mark = $this->getMarkClose($symbol);
            $this->positionsLogger->info('Mark price fetched', ['mark' => $mark]);

            /* ------------------ 4) ATR & SL/TP (preview) ------------------ */
            $atr       = $this->atrCalculator->compute($ohlc, $atrLookback, $atrMethod);
            $stopDist  = $atrKStop * $atr;
            $stopPct   = $stopDist / max(1e-9, $mark);
            $slRawATR  = $this->atrCalculator->stopFromAtr($mark, $atr, $atrKStop, $side);
            $tpRawATR  = $this->computeTpPrice($side, $mark, $stopDist, $tpRMultiple);

            $this->positionsLogger->info('ATR preview SL/TP (will be overridden by absolute USDT after sizing)', [
                'entry'     => $mark,
                'atr'       => $atr,
                'atr_tf'    => $atrTimeframe,
                'k_stop'    => $atrKStop,
                'stop_dist' => $stopDist,
                'stop_pct'  => $stopPct,
                'sl_raw_atr'=> $slRawATR,
                'tp_raw_atr'=> $tpRawATR,
            ]);

            /* ------------------ 5) Leverage ------------------ */
            // Plancher 2x conservé (adapter à 1/3 si besoin).
            $notionalRisk = ($budgetCapUsdt * $riskPct) / max(1e-9, $stopPct);
            $levFloor     = (int)\ceil($notionalRisk / $budgetCapUsdt);
            $levFromSizing= max(1, $levFloor);

            $currentLev = $this->getCurrentLeverageSafe($symbol);
            $targetLev  = max(4, min($maxLev, $levFromSizing));
            $factor     = $currentLev > 0 ? $targetLev / $currentLev : $targetLev;

            $this->positionsLogger->info('Leverage adjust', [
                'current' => $currentLev,
                'lev_from_sizing' => $levFromSizing,
                'target'  => $targetLev,
                'factor_vs_current' => $factor,
            ]);

            $this->bitmartPositions->setLeverage($symbol, $targetLev, $openType);
            $this->positionsLogger->info('Leverage set', [
                'symbol'   => $symbol,
                'leverage' => $targetLev,
                'openType' => $openType,
            ]);

            /* ------------------ 6) Contracts sizing ------------------ */
            // (A) Contraint par budget
            $notionalBudget   = $budgetCapUsdt * $targetLev;
            $contractsBudget  = floor(($notionalBudget / ($mark * $ctSize)) / $qtyStep) * $qtyStep;

            // (B) Contraint par risque absolu (distance ATR)
            $contractsRisk    = floor(($riskAbsUsdt / ($stopDist * $ctSize)) / $qtyStep) * $qtyStep;

            // (C) Final borné par min/max/marketCap
            $contracts = (int)max(
                $minVol,
                min($contractsBudget, $contractsRisk, $maxVol, $marketCap ?? INF)
            );

            $this->positionsLogger->info('Contracts sizing', [
                'contracts_budget' => $contractsBudget,
                'contracts_risk'   => $contractsRisk,
                'contracts_final'  => $contracts,
                'budget_used_usdt' => $budgetCapUsdt,
                'risk_abs_usdt'    => $riskAbsUsdt,
            ]);

            /* ------------------ 6bis) TP/SL ABSOLUS (USDT) ------------------ */
            $qtyNotional = max(1e-9, $contracts * $ctSize);

            if ($side === 'long') {
                // long: PnL = qty*(price - entry)
                $slRaw = $mark - ($riskAbsUsdt / $qtyNotional);
                $tpRaw = $mark + ($tpAbsUsdt   / $qtyNotional);
            } else {
                // short: PnL = qty*(entry - price)
                $slRaw = $mark + ($riskAbsUsdt / $qtyNotional);
                $tpRaw = $mark - ($tpAbsUsdt   / $qtyNotional);
            }

            $slQ = $this->quantizeToStep($slRaw, $tick);
            $tpQ = $this->quantizeToStep($tpRaw, $tick);

            $this->positionsLogger->info('SL/TP absolute override (USDT)', [
                'entry'         => $mark,
                'qty_notional'  => $qtyNotional,
                'tp_abs_usdt'   => $tpAbsUsdt,
                'sl_abs_usdt'   => $riskAbsUsdt,
                'sl_q'          => $slQ,
                'tp_q'          => $tpQ,
            ]);

            /* ------------------ 7) Market order avec presets ------------------ */
            $clientOrderId = 'SF_' . bin2hex(random_bytes(8));
            $bodyOpen = [
                'symbol'          => $symbol,
                'client_order_id' => $clientOrderId,
                'side'            => $this->mapSideOpen($side),
                'mode'            => 1,
                'type'            => 'market',
                'open_type'       => $openType,
                'size'            => $contracts,
                'preset_take_profit_price_type' => -2, // mark/fair
                'preset_stop_loss_price_type'   => -2, // mark/fair
                'preset_take_profit_price'      => (string)$tpQ,
                'preset_stop_loss_price'        => (string)$slQ,
            ];
            $this->positionsLogger->info('Submitting market order', $bodyOpen);

            $submit = $this->ordersService->create($bodyOpen);
            $this->positionsLogger->info('Market order response', $submit);

            if (($submit['code'] ?? 0) !== 1000) {
                throw new RuntimeException('submit-order error: ' . json_encode($submit));
            }
            $orderId = $submit['data']['order_id'] ?? null;
            if ($expireAfterSec !== null) {
                try {
                    $this->scheduleCancelAllAfter($symbol, $expireAfterSec);
                } catch (\Throwable $e) {
                    $this->positionsLogger->warning('scheduleCancelAllAfter failed', [
                        'symbol' => $symbol,
                        'timeout' => $expireAfterSec,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            /* ------------------ 8) Résultat final ------------------ */
            $result = [
                'symbol'     => $symbol,
                'side'       => $side,
                'timeframe'  => $timeframe,
                'order_id'   => $orderId,
                'entry_mark' => $mark,
                'sl'         => $slQ,
                'tp'         => $tpQ,
                'contracts'  => $contracts,
                'atr'        => $atr,
                'leverage'   => $targetLev,
                'budget_used_usdt' => $budgetCapUsdt,
                'risk_abs_usdt'    => $riskAbsUsdt,
            ];
            $this->positionsLogger->info('=== PositionOpener:end ===', $result);

            return $result;

        } catch (Throwable $e) {
            $this->positionsLogger->error('PositionOpener failed', [
                'error'  => $e->getMessage(),
                'symbol' => $symbol,
                'side'   => $side ?? null,
            ]);
            throw $e;
        }
    }

    private function doOpenLimitWithPct(
        string $symbol,
        string $finalSideUpper,
        float $marginUsdt,
        int   $leverage,
        float $slPct,
        float $tpPct,
        string $timeframe,
        array $meta = []
    ): array {
        $side = strtolower($finalSideUpper);           // 'long'|'short'
        $this->positionsLogger->info('=== PositionOpener:openLimit ===', compact(
            'symbol','side','timeframe','marginUsdt','leverage','slPct','tpPct','meta'
        ));

        /* 1) Détails contrat */
        $details = $this->getContractDetails($symbol);
        $tick    = (float)($details['price_precision'] ?? 0.0);
        $qtyStep = (float)($details['vol_precision']   ?? 0.0);
        $ctSize  = (float)($details['contract_size']   ?? 0.0);
        $minVol  = (int)  ($details['min_volume']      ?? 1);
        $maxVol  = (int)  ($details['max_volume']      ?? PHP_INT_MAX);
        $marketCap = isset($details['market_max_volume']) && (int)$details['market_max_volume'] > 0
            ? (int)$details['market_max_volume'] : null;

        if ($tick<=0 || $qtyStep<=0 || $ctSize<=0 || $minVol<=0) {
            throw new \RuntimeException("Invalid contract details");
        }

        /* 2) Mark & limite arrondie au tick */
        $mark  = $this->getMarkClose($symbol);
        $limit = $this->quantizeToStep($mark, $tick);  // même logique que ta commande

        /* 3) Levier = 1 (fail suave si déjà réglé) */
        try { $this->bitmartPositions->setLeverage($symbol, $leverage, 'isolated'); } catch (\Throwable) {}

        /* 4) Sizing : notional = marge×levier → contrats */
        $notional     = $marginUsdt * $leverage;
        $contractsRaw = $notional / max(1e-12, $limit * $ctSize);
        $contractsQ   = (int) $this->quantizeQty($contractsRaw, max(1e-9, $qtyStep));
        $upper        = $marketCap !== null ? min($maxVol, $marketCap) : $maxVol;

        if ($contractsQ < $minVol) {
            throw new \RuntimeException(sprintf(
                "Budget insuffisant pour %s @levier=%dx : %.6f < minVol=%d (valeur 1 contrat≈%.4f USDT)",
                $symbol, $leverage, $contractsRaw, $minVol, $ctSize * $limit
            ));
        }
        $contracts = (int) max($minVol, min($contractsQ, $upper));

        /* 5) SL/TP % (sur prix limite) puis quantize */
        if ($side === 'long') {
            $slRaw = $limit * (1.0 - $slPct);
            $tpRaw = $limit * (1.0 + $tpPct);
        } else {
            $slRaw = $limit * (1.0 + $slPct);
            $tpRaw = $limit * (1.0 - $tpPct);
        }
        $slQ = $this->quantizeToStep($slRaw, $tick);
        $tpQ = $this->quantizeToStep($tpRaw, $tick);

        /* 6) LIMIT avec presets TP/SL (price_type=2 mark) + stp_mode optionnel */
        $clientOrderId = 'LIM_' . bin2hex(random_bytes(6));
        $payload = [
            'symbol'                        => $symbol,
            'client_order_id'               => $clientOrderId,
            'side'                          => $this->mapSideOpen($side), // 1/4 oneway
            'mode'                          => 1,                          // GTC
            'type'                          => 'limit',
            'open_type'                     => 'isolated',
            'leverage'                      => (string)$leverage,
            'size'                          => $contracts,
            'price'                         => (string)$limit,
            'preset_take_profit_price_type' => 2, // 1=last, 2=fair/mark
            'preset_stop_loss_price_type'   => 2,
            'preset_take_profit_price'      => (string)$tpQ,
            'preset_stop_loss_price'        => (string)$slQ,
            'stp_mode'                      => 1, // cancel_maker (optionnel)
        ];
        $res = $this->ordersService->create($payload);
        if (($res['code'] ?? 0) !== 1000) {
            throw new \RuntimeException('submit-order error: ' . json_encode($res, JSON_UNESCAPED_SLASHES));
        }
        $orderId = $res['data']['order_id'] ?? null;

        // Persister l'orderId dans ContractPipeline
        if ($orderId) {
            try {
                $this->pipelineRepository->updateOrderIdBySymbol($symbol, $orderId);
                $this->positionsLogger->info('OrderId persisted in ContractPipeline', [
                    'symbol' => $symbol,
                    'order_id' => $orderId
                ]);
            } catch (\Throwable $e) {
                $this->positionsLogger->warning('Failed to persist orderId in ContractPipeline', [
                    'symbol' => $symbol,
                    'order_id' => $orderId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        /* 7) (Optionnel) Position TP/SL “plan_category=2” */
        // Si tu veux la couche position en plus des presets:
        try {
            $reduceSide = $this->mapSideReduce($side); // 3: close long | 2: close short
            $this->submitPositionTpSl(
                symbol: $symbol, orderType: 'take_profit', side: $reduceSide,
                triggerPrice: (string)$tpQ, priceType: 2, executivePrice: (string)$tpQ, category: 'limit'
            );
            $this->submitPositionTpSl(
                symbol: $symbol, orderType: 'stop_loss', side: $reduceSide,
                triggerPrice: (string)$slQ, priceType: 2, executivePrice: (string)$slQ, category: 'limit'
            );
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('submitPositionTpSl failed; presets still active', ['error' => $e->getMessage()]);
        }

        $out = [
            'symbol'     => $symbol,
            'side'       => $side,
            'timeframe'  => $timeframe,
            'order_id'   => $orderId,
            'limit'      => $limit,
            'sl'         => $slQ,
            'tp'         => $tpQ,
            'contracts'  => $contracts,
            'leverage'   => $leverage,
            'notional'   => $notional,
            'client_order_id' => $clientOrderId,
        ];
        $this->positionsLogger->info('=== PositionOpener:openLimit:end ===', $out);
        return $out;
    }

    public function openLimitAutoLevWithTpSlPct(
        string $symbol,
        string $finalSideUpper,          // 'LONG' | 'SHORT'
        float  $marginUsdt = 5.0,
        float  $slRoi      = 0.05,       // 5% ROI (ex: 0.07 pour -7%)
        float  $tpRoi      = 0.10,       // 10% ROI (ex: 0.12 pour +12%)
        string $timeframe  = 'manual',
        array  $meta       = [],
        ?int   $expireAfterSec = null
    ): array {
        $side = strtolower($finalSideUpper);

        // 1) Détails contrat
        $details = $this->getContractDetails($symbol);
        $tick    = (float)($details['price_precision'] ?? 0.0);
        $qtyStep = (float)($details['vol_precision']   ?? 0.0);
        $ctSize  = (float)($details['contract_size']   ?? 0.0);
        $minVol  = (int)  ($details['min_volume']      ?? 1);
        $maxVol  = (int)  ($details['max_volume']      ?? PHP_INT_MAX);
        $maxLev  = (int)  ($details['max_leverage']    ?? 50);
        $marketCap = isset($details['market_max_volume']) && (int)$details['market_max_volume'] > 0
            ? (int)$details['market_max_volume'] : null;

        if ($tick<=0 || $qtyStep<=0 || $ctSize<=0 || $minVol<=0) {
            throw new \RuntimeException("Invalid contract details");
        }

        // 2) Prix limite ~= mark arrondi au tick
        $mark  = $this->getMarkClose($symbol);
        $limit = $this->quantizeToStep($mark, $tick);

        // 3) Config (risque absolu)
        $cfg          = $this->tradingParameters->all();
        $riskAbsUsdt  = (float)($cfg['risk']['abs_usdt'] ?? 3.0);

// 4) Levier cible (ta règle actuelle) + contrainte minVol/budget
        $leverageTarget = max(4, (int)floor($maxLev / 2)); // ou ta règle dynamique variation_pct
        $leverage = min($maxLev, $leverageTarget);

// 5) S'assure que minVol est réalisable dans le budget souhaité (marge<=marginUsdt)
        $notionalForMinVol = $minVol * $limit * $ctSize;
        $leverageNeededForMinVol = (int)ceil($notionalForMinVol / max(1e-12, $marginUsdt));
        if ($leverageNeededForMinVol > $maxLev) {
            throw new \RuntimeException(sprintf(
                "Budget insuffisant: minVol=%d nécessite levier≥%dx pour marge=%g USDT (maxLev=%dx).",
                $minVol, $leverageNeededForMinVol, $marginUsdt, $maxLev
            ));
        }
        $leverage = max($leverage, $leverageNeededForMinVol);

// 6) Calcul budget dur (notional max autorisé par la marge visée)
        $notionalMax = $marginUsdt * $leverage;

// 7) Sizing par le risque avec le LEVIER RÉEL
//    stopDist = %mouvement prix correspondant à slRoi/leverage
        $slPct   = abs($slRoi) / max(1e-12, $leverage);
        $stopDist = $limit * $slPct;
        $contractsRiskF = $riskAbsUsdt / max(1e-12, $stopDist * $ctSize);
        $contractsRiskQ = $this->quantizeQty($contractsRiskF, max(1e-9, $qtyStep));

// 8) Sizing par BUDGET (cap dur) et bornes exchange
        $contractsBud = $this->quantizeQty(
            $notionalMax / max(1e-12, $limit * $ctSize),
            max(1e-9, $qtyStep)
        );
        $upperCap = $marketCap !== null ? min($maxVol, $marketCap) : $maxVol;

// 9) Candidat sans dépasser le budget (ni caps), mais ≥ minVol
        $candidate = max($minVol, min($contractsRiskQ, $contractsBud, $upperCap));

// Si le risque demande plus que le budget, tente d'augmenter le levier (jusqu'à maxLev)
        if ($candidate < max($minVol, min($contractsRiskQ, $upperCap))) {
            $contractsNeeded = max($minVol, min($contractsRiskQ, $upperCap));
            $notionalNeeded  = $contractsNeeded * $limit * $ctSize;
            $levNeeded       = (int)ceil($notionalNeeded / max(1e-12, $marginUsdt));
            if ($levNeeded <= $maxLev) {
                $leverage = max($leverage, $levNeeded);
                $notionalMax = $marginUsdt * $leverage;
                $contractsBud = $this->quantizeQty(
                    $notionalMax / max(1e-12, $limit * $ctSize),
                    max(1e-9, $qtyStep)
                );
                $candidate = max($minVol, min($contractsRiskQ, $contractsBud, $upperCap));
            } else {
                // On reste dans le budget: on acceptera une taille < risque idéal
                $candidate = max($minVol, min($contractsBud, $upperCap));
            }
        }

        $contracts = (int)$candidate;

// 10) Recalcule TP/SL avec le levier réel (déjà fait ci-dessus pour slPct)
        $tpPct = abs($tpRoi) / max(1e-12, $leverage);
        if ($side === 'long') {
            $slRaw = $limit * (1.0 - $slPct);
            $tpRaw = $limit * (1.0 + $tpPct);
        } else {
            $slRaw = $limit * (1.0 + $slPct);
            $tpRaw = $limit * (1.0 - $tpPct);
        }
        $slQ = $this->quantizeToStep($slRaw, $tick);
        $tpQ = $this->quantizeToStep($tpRaw, $tick);

// 11) Estimations honnêtes pour retour et contrôle
        $notionalReal = $contracts * $limit * $ctSize;
        $marginEst    = $notionalReal / max(1e-12, $leverage);

// 12) Met à jour le payload + setLeverage
        $clientOrderId = 'LIM_' . bin2hex(random_bytes(6));
        $payload = [
            'symbol'                        => $symbol,
            'client_order_id'               => $clientOrderId,
            'side'                          => $this->mapSideOpen($side),
            'mode'                          => 1,
            'type'                          => 'limit',
            'open_type'                     => 'isolated',
            'leverage'                      => (string)$leverage,
            'size'                          => $contracts,
            'price'                         => (string)$limit,
            'preset_take_profit_price_type' => 2,
            'preset_stop_loss_price_type'   => 2,
            'preset_take_profit_price'      => (string)$tpQ,
            'preset_stop_loss_price'        => (string)$slQ,
            'stp_mode'                      => 1,
        ];
        $this->bitmartPositions->setLeverage($symbol, $leverage, 'isolated');
        $res = $this->ordersService->create($payload);
        if (($res['code'] ?? 0) !== 1000) {
            throw new \RuntimeException('submit-order error: ' . json_encode($res, JSON_UNESCAPED_SLASHES));
        }
        $orderId = $res['data']['order_id'] ?? null;

        if ($expireAfterSec !== null) {
            try { $this->scheduleCancelAllAfter($symbol, $expireAfterSec); } catch (\Throwable $e) {
                $this->positionsLogger->warning('scheduleCancelAllAfter failed', [
                    'symbol' => $symbol, 'timeout' => $expireAfterSec, 'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'symbol'     => $symbol,
            'side'       => $side,
            'timeframe'  => $timeframe,
            'order_id'   => $orderId,
            'limit'      => $limit,
            'sl'         => $slQ,
            'tp'         => $tpQ,
            'contracts'  => $contracts,
            'leverage'   => $leverage,
            'notional'   => $notionalMax,
            'client_order_id' => $clientOrderId,
            'meta'       => $meta,
        ];
    }


    /** Quantize une quantité au pas (arrondi vers le bas) */
    private function quantizeQty(float $v, float $step): float {
        $this->ensurePositive($step, 'qty_step');
        return floor($v / $step) * $step;
    }


    // ================= Helpers métier =================

    /** Mapping ouverture one-way: long|buy -> 1 ; short|sell -> 4 */
    private function mapSideOpen(string $side): int
    {
        return match (strtolower($side)) {
            'long','buy'  => 1,
            'short','sell'=> 4,
            default       => throw new InvalidArgumentException("side invalide: $side"),
        };
    }

    /** Mapping reduce-only: fermer long => sell reduce(3), fermer short => buy reduce(2) */
    private function mapSideReduce(string $openedSide): int
    {
        return match (strtolower($openedSide)) {
            'long','buy'  => 3, // sell reduce
            'short','sell'=> 2, // buy reduce
            default       => throw new InvalidArgumentException("openedSide invalide: $openedSide"),
        };
    }

    /** Quantise au step le plus proche (tick/step prix) */
    private function quantizeToStep(float $v, float $step): float
    {
        $this->ensurePositive($step, 'price_tick');
        return round($v / $step) * $step;
    }

    /** TP à R-multiple de la distance de stop */
    private function computeTpPrice(string $side, float $entry, float $stopDist, float $r): float
    {
        $d = $r * $stopDist;
        return \in_array($side, ['long','buy'], true) ? $entry + $d : $entry - $d;
    }

    /** SL arithmétique (si besoin hors ATR helper) */
    private function computeSlPrice(string $side, float $entry, float $stopDist): float
    {
        return \in_array($side, ['long','buy'], true) ? $entry - $stopDist : $entry + $stopDist;
    }

    private function ensurePositive(float $value, string $name): void
    {
        if (!is_finite($value) || $value <= 0.0) {
            $this->positionsLogger->error("$name doit être > 0 (reçu: $value)");
            throw new RuntimeException("$name doit être > 0 (reçu: $value)");
        }
    }

    // ================= HTTP BitMart =================

    /** Détails contrat — récupère depuis la BDD (entité Contract) */
    private function getContractDetails(string $symbol): array
    {
        $contract = $this->contractRepository->find($symbol);
        if (!$contract) {
            throw new RuntimeException("Contract $symbol not found in database");
        }

        return [
            'symbol' => $contract->getSymbol(),
            'status' => $contract->getStatus(),
            'price_precision' => $contract->getPricePrecision(),
            'vol_precision' => $contract->getVolPrecision(),
            'contract_size' => $contract->getContractSize(),
            'min_volume' => $contract->getMinVolume(),
            'max_volume' => $contract->getMaxVolume(),
            'market_max_volume' => $contract->getMarketMaxVolume(),
            'max_leverage' => $contract->getMaxLeverage(),
            'min_leverage' => $contract->getMinLeverage(),
        ];
    }

    /** MarkPrice K-line (step=1) → close_price de la dernière bougie */
    private function getMarkClose(string $symbol): float
    {
        $now = time();
        $rows = $this->bitmartPublic->getMarkPriceKline(
            symbol: $symbol,
            step: 1,
            limit: 2,
            startTime: $now - 120,
            endTime: $now
        );
        if (!$rows || !is_array($rows)) {
            throw new RuntimeException('markprice-kline: pas de données');
        }
        $last  = end($rows);
        $close = (float)($last['close_price'] ?? 0.0);
        if ($close <= 0.0) {
            throw new RuntimeException('markprice-kline: close_price invalide');
        }
        return $close;
    }

    /** (optionnel) Cap du levier par bracket ; retourne null si échec silencieux */
    private function getMaxLeverageFromBracketSafe(string $symbol, float $notional): ?int
    {
        try {
            $brackets = $this->bitmartPublic->getLeverageBrackets($symbol);
            $maxLev = null;
            foreach ($brackets as $b) {
                $cap = (float)($b['notional_cap'] ?? INF);
                if ($notional <= $cap) {
                    $maxLev = (int)($b['max_leverage'] ?? 0);
                    break;
                }
            }
            return $maxLev ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Règle le levier côté exchange (SIGNED) */
    private function setLeverage(string $symbol, int $leverage, string $openType = 'isolated'): void
    {
        $this->positionsLogger->info('submit-leverage payload', [
            'symbol' => $symbol,
            'leverage' => $leverage,
            'open_type' => $openType,
        ]);

        $resp = $this->bitmartPositions->setLeverage($symbol, $leverage, $openType);
        $this->positionsLogger->info('submit-leverage response', $resp);

        if (($resp['code'] ?? 0) !== 1000) {
            throw new RuntimeException('submit-leverage error: ' . json_encode($resp, JSON_UNESCAPED_SLASHES));
        }
    }

    /** Valide et normalise un plan-order TP/SL avant envoi */
    private function buildPlanPayload(
        string $symbol,
        int $sideReduce,            // 2 buy_close_short | 3 sell_close_long
        string $type,               // 'stop_loss' | 'take_profit'
        int $size,
        float $triggerQ,
        float $execQ,
        int $priceType = 2,         // 2 = fair/mark
        ?int $planCategory = 2,     // 2 = Position TP/SL
        string $category = 'market' // 'market' | 'limit'
    ): array {
        if (!\in_array($type, ['stop_loss','take_profit'], true)) {
            throw new InvalidArgumentException("Invalid plan type: $type");
        }
        if (!\in_array($sideReduce, [2,3], true)) {
            throw new InvalidArgumentException("Invalid reduce side: $sideReduce (need 2 or 3)");
        }
        if ($size <= 0) {
            throw new InvalidArgumentException("Invalid size: $size");
        }
        if (!\in_array($priceType, [1,2], true)) {
            throw new InvalidArgumentException("Invalid price_type: $priceType");
        }
        if (!\in_array($category, ['market','limit'], true)) {
            throw new InvalidArgumentException("Invalid category: $category");
        }

        $payload = [
            'symbol'          => $symbol,
            'side'            => $sideReduce,
            'type'            => $type,
            'size'            => $size,
            'trigger_price'   => (string)$triggerQ,
            'executive_price' => (string)$execQ,
            'price_type'      => $priceType,
            'category'        => $category,
            'client_order_id' => strtoupper(substr($type,0,2)) . '_' . bin2hex(random_bytes(6)),
        ];
        if ($planCategory !== null) {
            $payload['plan_category'] = $planCategory; // 2 = Position TP/SL
        }
        return $payload;
    }

    /** Envoie un plan TP/SL avec fallback: market→limit→price_type1→sans plan_category */
    private function submitPlanOrderWithFallback(array $payload): array
    {
        // essai #1 : tel quel
        $this->positionsLogger->info('PlanOrder try#1 JSON', ['json' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);
        $res = $this->trailOrders->create($payload);
        if (($res['code'] ?? 0) === 1000) return $res;

        $code = (int)($res['code'] ?? 0);
        $this->positionsLogger->error('PlanOrder try#1 failed', ['code' => $code, 'resp' => $res]);

        // essai #2 : switch category -> 'limit'
        $p2 = $payload; $p2['category'] = 'limit';
        $this->positionsLogger->info('PlanOrder try#2 JSON', ['json' => json_encode($p2, JSON_UNESCAPED_SLASHES)]);
        $res2 = $this->trailOrders->create($p2);
        if (($res2['code'] ?? 0) === 1000) return $res2;

        $code2 = (int)($res2['code'] ?? 0);
        $this->positionsLogger->error('PlanOrder try#2 failed', ['code' => $code2, 'resp' => $res2]);

        // essai #3 : switch price_type -> 1 (last_price)
        $p3 = $p2; $p3['price_type'] = 1;
        $this->positionsLogger->info('PlanOrder try#3 JSON', ['json' => json_encode($p3, JSON_UNESCAPED_SLASHES)]);
        $res3 = $this->trailOrders->create($p3);
        if (($res3['code'] ?? 0) === 1000) return $res3;

        $code3 = (int)($res3['code'] ?? 0);
        $this->positionsLogger->error('PlanOrder try#3 failed', ['code' => $code3, 'resp' => $res3]);

        // essai #4 : enlever plan_category
        $p4 = $p3; unset($p4['plan_category']);
        $this->positionsLogger->info('PlanOrder try#4 JSON', ['json' => json_encode($p4, JSON_UNESCAPED_SLASHES)]);
        $res4 = $this->trailOrders->create($p4);
        if (($res4['code'] ?? 0) === 1000) return $res4;

        $this->positionsLogger->error('PlanOrder all tries failed', [
            'first' => $res, 'second' => $res2, 'third' => $res3, 'fourth' => $res4
        ]);
        throw new RuntimeException('submit-plan-order failed after 4 attempts: ' . json_encode($res4, JSON_UNESCAPED_SLASHES));
    }

    private function getCurrentLeverageSafe(string $symbol): int
    {
        try {
            $resp = $this->bitmartPositions->list(['symbol' => $symbol]);
            $data = $resp['data'] ?? null;

            $leverage = null;
            if (\is_array($data)) {
                if (isset($data['leverage'])) {
                    $leverage = (int)$data['leverage'];
                } elseif (isset($data[0]['leverage'])) {
                    $leverage = (int)$data[0]['leverage'];
                }
            }

            $lev = $leverage ?? 0;
            return $lev > 0 ? $lev : 1;
        } catch (Throwable $e) {
            $this->positionsLogger->warning('getCurrentLeverageSafe failed, defaulting to 1', [
                'symbol' => $symbol, 'error' => $e->getMessage()
            ]);
            return 1;
        }
    }

    private function quantizeDown(float $v, float $step): float { return floor($v/$step)*$step; }
    private function quantizeUp(float $v, float $step): float { return ceil($v/$step)*$step; }

    /** Programme l'annulation de TOUS les ordres du symbole après N secondes.
     *  timeout: secondes (min 5). Mettre 0 pour désactiver le timer.
     */
    public function scheduleCancelAllAfter(string $symbol, int $timeoutSeconds): array
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('timeoutSeconds doit être >= 0 (0 pour désactiver)');
        }
        if ($timeoutSeconds !== 0 && $timeoutSeconds < 5) {
            throw new \InvalidArgumentException('timeoutSeconds doit être >= 5 (ou 0 pour annuler le réglage)');
        }

        $this->positionsLogger->info('Submitting cancel-all-after', [
            'symbol' => $symbol,
            'timeout' => $timeoutSeconds,
        ]);

        $res = $this->trailOrders->cancelAllAfter($symbol, $timeoutSeconds);
        $this->positionsLogger->info('cancel-all-after response', $res);

        if (($res['code'] ?? 0) !== 1000) {
            throw new \RuntimeException('cancel-all-after error: ' . json_encode($res, JSON_UNESCAPED_SLASHES));
        }
        return $res;
    }

    /**
     * Calcule les métriques ROI à partir de l'entrée, du TP, du SL et du risque max.
     *
     * ROI% = ( |Pe - Ptp| / Pe * 100 ) * ( RiskMax% / ( |Psl - Pe| / Pe * 100 ) )
     *
     * @param float  $entry          Prix d'entrée
     * @param float  $tp             Take Profit
     * @param float  $sl             Stop Loss
     * @param float  $riskMaxPercent Risque max toléré sur la marge (ex: 0.07 pour 7%)
     * @param string $sideUpper      'LONG' | 'SHORT'
     *
     * @return array{
     *   tp_pct: float,     // % variation jusqu'au TP (prix)
     *   sl_pct: float,     // % variation jusqu'au SL (prix)
     *   lev_opt: float,    // levier optimal pour respecter RiskMax%
     *   roi_pct: float,    // ROI attendu en % du capital engagé
     *   side: string       // 'LONG' | 'SHORT'
     * }
     */
    private function calcRoiMetrics(
        float $entry,
        float $tp,
        float $sl,
        float $riskMaxPercent,
        string $sideUpper
    ): array {
        $side = strtolower($sideUpper);

        if ($entry <= 0.0) {
            throw new \InvalidArgumentException("Entry price must be > 0.");
        }
        if ($riskMaxPercent <= 0.0) {
            throw new \InvalidArgumentException("RiskMax% must be > 0 (e.g. 0.07 for 7%).");
        }

        // Validation directionnelle simple (non bloquante : warnings possibles selon ta politique)
        if ($side === 'long') {
            if ($tp <= $entry) {
                // logger un avertissement si tu veux
            }
            if ($sl >= $entry) {
                // logger un avertissement si tu veux
            }
        } elseif ($side === 'short') {
            if ($tp >= $entry) {
                // logger un avertissement si tu veux
            }
            if ($sl <= $entry) {
                // logger un avertissement si tu veux
            }
        } else {
            throw new \InvalidArgumentException("sideUpper must be 'LONG' or 'SHORT'.");
        }

        // Variations en % (du PRIX), indépendantes du sens
        $slPct = abs($sl - $entry) / $entry * 100.0;
        $tpPct = abs($tp - $entry) / $entry * 100.0;

        if ($slPct == 0.0) {
            throw new \InvalidArgumentException("Stop Loss identique à l'entrée : calcul impossible.");
        }

        // Levier optimal pour ne pas dépasser le risque max (sur la marge)
        // lev_opt = RiskMax% / stop%
        $levOpt = ($riskMaxPercent * 100.0) / $slPct;

        // ROI attendu (en % du capital engagé)
        // roi% = tp% * lev_opt
        $roiPct = $tpPct * $levOpt;

        return [
            'tp_pct'  => $tpPct,
            'sl_pct'  => $slPct,
            'lev_opt' => $levOpt,
            'roi_pct' => $roiPct,
            'side'    => strtoupper($sideUpper),
        ];
    }
    public function openLimitAutoLevWithSr(
        string $symbol,
        string $finalSideUpper,        // 'LONG' | 'SHORT'
        float  $marginUsdt = 5.0,
        float  $riskMaxPct = 0.07,     // 7% risque max sur la marge
        float  $rMultiple  = 2.0,      // Take Profit à 2R par défaut
        array  $meta = [],
        ?int   $expireAfterSec = 120   // annule les ordres après 2 minutes
    ): array {
        $side = strtoupper($finalSideUpper);

        $this->positionsLogger->info('[SR] Étape 1: Récupération des détails du contrat', [
            'symbol' => $symbol
        ]);
        $details = $this->getContractDetails($symbol);
        $tick    = (float)($details['price_precision'] ?? 0.0);
        $qtyStep = (float)($details['vol_precision']   ?? 0.0);
        $ctSize  = (float)($details['contract_size']   ?? 0.0);
        $maxLev  = (int)  ($details['max_leverage']    ?? 50);

        if ($tick <= 0 || $qtyStep <= 0 || $ctSize <= 0) {
            $this->positionsLogger->error('[SR] Détails contrat invalides', compact('tick', 'qtyStep', 'ctSize'));
            throw new \RuntimeException("Invalid contract details for $symbol");
        }

        $this->positionsLogger->info('[SR] Étape 2: Récupération du prix mark', [
            'symbol' => $symbol
        ]);
        $mark  = $this->getMarkClose($symbol);
        $limit = $this->quantizeToStep($mark, $tick);
        $this->positionsLogger->info('[SR] Prix d\'entrée arrondi', [
            'mark' => $mark,
            'limit' => $limit
        ]);

        $cfg = $this->tradingParameters->all();
        $atrLookback  = (int)($cfg['atr']['lookback'] ?? 14);
        $atrMethod    = (string)($cfg['atr']['method'] ?? 'wilder');
        $atrTimeframe = (string)($cfg['atr']['timeframe'] ?? '5m');
        $atrSeries = $this->klineRepository->findLastKlines(
            symbol: $symbol,
            timeframe: $atrTimeframe,
            limit: max($atrLookback + 1, 200)
        );
        if (count($atrSeries) <= $atrLookback) {
            $this->positionsLogger->error('[SR] Pas assez de bougies pour l\'ATR', [
                'timeframe' => $atrTimeframe,
                'count'     => count($atrSeries),
                'lookback'  => $atrLookback
            ]);
            throw new \RuntimeException("Not enough OHLC for ATR ($symbol tf=$atrTimeframe)");
        }
        $atrValue = $this->atrCalculator->compute($atrSeries, $atrLookback, $atrMethod);
        $this->positionsLogger->info('[SR] ATR source', [
            'timeframe' => $atrTimeframe,
            'lookback'  => $atrLookback,
            'atr'       => $atrValue
        ]);

        $this->positionsLogger->info('[SR] Étape 3: Chargement des klines pour S/R', [
            'symbol' => $symbol
        ]);
        $klines15m = $this->klineRepository->findLastKlines(
            symbol: $symbol,
            timeframe: '15m',
            limit: 200
        );

        if (count($klines15m) < 50) {
            $this->positionsLogger->error('[SR] Pas assez de klines pour la détection S/R', [
                'count' => count($klines15m)
            ]);
            throw new \RuntimeException("Not enough klines for SR detection ($symbol)");
        }
        // Log de la date du dernier kline et de la date courante alignée
        $lastKline = end($klines15m);
        $lastOpen  = $lastKline['open_time'] ?? null; // adapte si ta BDD stocke différemment
        if ($lastOpen !== null) {
            $lastOpenDt = (new \DateTimeImmutable())->setTimestamp((int)$lastOpen)->setTimezone(new \DateTimeZone('UTC'));
            $alignedDt  = \App\Util\TimeframeHelper::getAlignedOpen('15m');

            $this->positionsLogger->info('[SR] Comparaison temps klines', [
                'last_kline_open' => $lastOpenDt->format('Y-m-d H:i:s T'),
                'aligned_now'     => $alignedDt->format('Y-m-d H:i:s T'),
                'now_utc'         => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s T'),
            ]);
        }


        $this->positionsLogger->info('[SR] Étape 4: Détection S/R');
        $sr = SrRiskHelper::findSupportResistance($klines15m);
        $sr['atr'] = $atrValue;

        $this->positionsLogger->info('[SR] Étape 5: Calcul du Stop Loss via S/R + ATR', [
            'side' => $side,
            'limit' => $limit,
            'supports' => $sr['supports'],
            'resistances' => $sr['resistances'],
            'atr' => $sr['atr'],
            'atr_tf' => $atrTimeframe
        ]);
        $slRaw = SrRiskHelper::chooseSlFromSr($side, $limit, $sr['supports'], $sr['resistances'], $sr['atr']);
        $slQ   = $this->quantizeToStep($slRaw, $tick);

        $this->positionsLogger->info('[SR] Étape 6: Calcul du levier optimal', [
            'limit' => $limit,
            'slQ' => $slQ,
            'riskMaxPct' => $riskMaxPct,
            'maxLev' => $maxLev
        ]);
        $levOptFloat = SrRiskHelper::leverageFromRisk($limit, $slQ, $riskMaxPct, $maxLev);
        $levFinal    = max(1, min($maxLev, (int)ceil($levOptFloat)));


        $this->positionsLogger->info('[SR] Étape 7: Calcul du Take Profit (R-multiple)', [
            'rMultiple' => $rMultiple
        ]);
        $stopPct = abs($slQ - $limit) / $limit;
        $tpTarget = $stopPct * $rMultiple;
        $tpRaw = ($side === 'LONG')
            ? $limit * (1.0 + $tpTarget)
            : $limit * (1.0 - $tpTarget);
        $tpQ = $this->quantizeToStep($tpRaw, $tick);

        $this->positionsLogger->info('[SR] Étape 8: Sizing de la position', [
            'marginUsdt' => $marginUsdt,
            'levOpt' => $levFinal,
            'limit' => $limit,
            'ctSize' => $ctSize,
            'qtyStep' => $qtyStep
        ]);
        $notionalMax = $marginUsdt * $levFinal;
        $contractsBud = $this->quantizeQty(
            $notionalMax / max(1e-12, $limit * $ctSize),
            max(1e-9, $qtyStep)
        );
        $contracts = (int)max(1, $contractsBud);

        $this->positionsLogger->info('[SR] Étape 9: Fixe le levier', [
            'levOpt' => ceil($levFinal)
        ]);
        $this->bitmartPositions->setLeverage($symbol, $levFinal, 'isolated');
        $this->waitLeverageSynchronized($symbol, $levFinal, 'isolated');

        $clientOrderId = 'SR_' . bin2hex(random_bytes(6));
        $payload = [
            'symbol'                        => $symbol,
            'client_order_id'               => $clientOrderId,
            'side'                          => $this->mapSideOpen(strtolower($side)),
            'mode'                          => 1,
            'type'                          => 'limit',
            'open_type'                     => 'isolated',
            'leverage'                      => (string)$levFinal,
            'size'                          => $contracts,
            'price'                         => (string)$limit,
            'preset_take_profit_price_type' => 2,
            'preset_stop_loss_price_type'   => 2,
            'preset_take_profit_price'      => (string)$tpQ,
            'preset_stop_loss_price'        => (string)$slQ,
            'stp_mode'                      => 1,
        ];

        $this->positionsLogger->info('[SR] Étape 10: Soumission de l\'ordre', $payload);
        $res = $this->ordersService->create($payload);
        // Mise à jour du pipeline
        try {
            $this->pipelineRepository->updateStatusBySymbol(
                symbol: $symbol,
                status: ContractPipeline::STATUS_ORDER_OPENED
            );
            $this->positionsLogger->info('[SR] Pipeline mis à jour -> STATUS_ORDER_OPENED', [
                'symbol' => $symbol,
                'order_id' => $res['data']['order_id'] ?? null,
                'status' => ContractPipeline::STATUS_ORDER_OPENED
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->error('[SR] Erreur lors de la mise à jour du pipeline', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
        }

        $this->positionsLogger->info('[SR] Réponse de l\'API BitMart', $res);
        if (($res['code'] ?? 0) !== 1000) {
            $this->positionsLogger->error('[SR] Erreur lors de la soumission de l\'ordre', [
                'response' => $res
            ]);
            throw new \RuntimeException('submit-order error: ' . json_encode($res, JSON_UNESCAPED_SLASHES));
        }
        $orderId = $res['data']['order_id'] ?? null;

        // Persister l'orderId dans ContractPipeline
        if ($orderId) {
            try {
                $this->pipelineRepository->updateOrderIdBySymbol($symbol, $orderId);
                $this->positionsLogger->info('[SR] OrderId persisted in ContractPipeline', [
                    'symbol' => $symbol,
                    'order_id' => $orderId
                ]);
            } catch (\Throwable $e) {
                $this->positionsLogger->warning('[SR] Failed to persist orderId in ContractPipeline', [
                    'symbol' => $symbol,
                    'order_id' => $orderId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($expireAfterSec !== null) {
            $this->positionsLogger->info('[SR] Étape 11: Programmation de l\'expiration auto', [
                'expireAfterSec' => $expireAfterSec
            ]);
            try {
                $this->trailOrders->cancelAllAfter($symbol, $expireAfterSec);
            } catch (\Throwable $e) {
                $this->positionsLogger->warning('scheduleCancelAllAfter failed', [
                    'symbol' => $symbol,
                    'timeout' => $expireAfterSec,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->positionsLogger->info('[SR] Étape 12: Retour final', [
            'symbol'     => $symbol,
            'side'       => $side,
            'order_id'   => $orderId,
            'client_order_id' => $clientOrderId,
            'limit'      => $limit,
            'sl'         => $slQ,
            'tp'         => $tpQ,
            'contracts'  => $contracts,
            'leverage'   => $levFinal,
            'metrics'    => $sr,
            'meta'       => $meta,
        ]);

        return [
            'symbol'     => $symbol,
            'side'       => $side,
            'order_id'   => $orderId,
            'client_order_id' => $clientOrderId,
            'limit'      => $limit,
            'sl'         => $slQ,
            'tp'         => $tpQ,
            'contracts'  => $contracts,
            'leverage'   => $levFinal,
            'metrics'    => $sr,
            'meta'       => $meta,
        ];
    }


    /**
     * Variante HIGH CONVICTION :
     * - Même logique S/R + ATR que openLimitAutoLevWithSr()
     * - Levier borné par $leverageCap (sans augmenter le risk %)
     * - Garde le sizing budget→notional, puis quantize au pas d’échange
     */
    public function openLimitHighConvWithSr(
        string $symbol,
        string $finalSideUpper,           // 'LONG' | 'SHORT'
        int    $leverageCap    = self::HC_DEFAULT_LEV_CAP,  // CAP HC (e.g. 50)
        float  $marginUsdt     = 60.0,                      // ton budget d’ouverture
        float  $riskMaxPct     = 0.07,                      // 7% risque max sur la marge (identique à SR)
        float  $rMultiple      = self::HC_DEFAULT_R_MULTIPLE,
        array  $meta           = [],
        ?int   $expireAfterSec = self::HC_DEFAULT_EXPIRE_SEC
    ): array {
        $side = strtoupper($finalSideUpper);

        // 1) Détails contrat
        $this->positionsLogger->info('[HC] Étape 1: Détails du contrat', ['symbol' => $symbol]);
        $details = $this->getContractDetails($symbol);
        $tick    = (float)($details['price_precision'] ?? 0.0);
        $qtyStep = (float)($details['vol_precision']   ?? 0.0);
        $ctSize  = (float)($details['contract_size']   ?? 0.0);
        $maxLev  = (int)  ($details['max_leverage']    ?? 50);

        if ($tick <= 0 || $qtyStep <= 0 || $ctSize <= 0) {
            $this->positionsLogger->error('[HC] Détails contrat invalides', compact('tick','qtyStep','ctSize'));
            throw new \RuntimeException("Invalid contract details for $symbol");
        }

        // 2) Prix (mark) & entrée
        $this->positionsLogger->info('[HC] Étape 2: Mark price', ['symbol' => $symbol]);
        $mark  = $this->getMarkClose($symbol);
        $limit = $this->quantizeToStep($mark, $tick);
        $this->positionsLogger->info('[HC] Prix d\'entrée arrondi', ['mark' => $mark, 'limit' => $limit]);

        $cfg = $this->tradingParameters->all();
        $atrLookback  = (int)($cfg['atr']['lookback'] ?? 14);
        $atrMethod    = (string)($cfg['atr']['method'] ?? 'wilder');
        $atrTimeframe = (string)($cfg['atr']['timeframe'] ?? '5m');
        $atrSeries = $this->klineRepository->findLastKlines(
            symbol: $symbol,
            timeframe: $atrTimeframe,
            limit: max($atrLookback + 1, 200)
        );
        if (\count($atrSeries) <= $atrLookback) {
            $this->positionsLogger->error('[HC] Pas assez de bougies pour l\'ATR', [
                'timeframe' => $atrTimeframe,
                'count'     => \count($atrSeries),
                'lookback'  => $atrLookback
            ]);
            throw new \RuntimeException("Not enough OHLC for ATR ($symbol tf=$atrTimeframe)");
        }
        $atrValue = $this->atrCalculator->compute($atrSeries, $atrLookback, $atrMethod);
        $this->positionsLogger->info('[HC] ATR source', [
            'timeframe' => $atrTimeframe,
            'lookback'  => $atrLookback,
            'atr'       => $atrValue
        ]);

        // 3) Klines pour S/R 15m
        $this->positionsLogger->info('[HC] Étape 3: Chargement des klines (S/R)', ['symbol' => $symbol]);
        $klines15m = $this->klineRepository->findLastKlines(symbol: $symbol, timeframe: '15m', limit: 200);
        if (\count($klines15m) < 50) {
            $this->positionsLogger->error('[HC] Pas assez de klines pour S/R', ['count' => \count($klines15m)]);
            throw new \RuntimeException("Not enough klines for SR detection ($symbol)");
        }

        // 4) Détection S/R & SL basé S/R + ATR
        $this->positionsLogger->info('[HC] Étape 4: Détection S/R');
        $sr = \App\Util\SrRiskHelper::findSupportResistance($klines15m);
        $sr['atr'] = $atrValue;

        $this->positionsLogger->info('[HC] Étape 5: Calcul SL via S/R + ATR', [
            'side' => $side, 'limit' => $limit,
            'supports' => $sr['supports'], 'resistances' => $sr['resistances'], 'atr' => $sr['atr'],
            'atr_tf' => $atrTimeframe
        ]);
        $slRaw = \App\Util\SrRiskHelper::chooseSlFromSr($side, $limit, $sr['supports'], $sr['resistances'], $sr['atr']);
        $slQ   = $this->quantizeToStep($slRaw, $tick);

        // 5) Levier optimal basé risque ⇒ borné par CAP HC et par maxLev exchange
        $this->positionsLogger->info('[HC] Étape 6: Calcul du levier optimal (cap HC)', [
            'riskMaxPct' => $riskMaxPct, 'maxLev' => $maxLev, 'capHC' => $leverageCap
        ]);
        $levOptRaw = \App\Util\SrRiskHelper::leverageFromRisk($limit, $slQ, $riskMaxPct, $maxLev);
        $levOpt    = min($levOptRaw, max(1, $leverageCap)); // bornage par CAP HC
        if ($levOpt <= 0.0) {
            throw new \RuntimeException("[HC] leverageFromRisk returned non-positive leverage.");
        }

        // 6) TP à R-multiple de la distance de stop
        $this->positionsLogger->info('[HC] Étape 7: Calcul TP (R-multiple)', ['rMultiple' => $rMultiple]);
        $stopPct  = abs($slQ - $limit) / max(1e-12, $limit);
        $tpTarget = $stopPct * $rMultiple;
        $tpRaw    = ($side === 'LONG') ? $limit * (1.0 + $tpTarget) : $limit * (1.0 - $tpTarget);
        $tpQ      = $this->quantizeToStep($tpRaw, $tick);

        // 7) Sizing par budget (marge × levier) → contrats
        $this->positionsLogger->info('[HC] Étape 8: Sizing (budget & cap)', [
            'marginUsdt' => $marginUsdt, 'levOptRaw' => $levOptRaw, 'levOptCapped' => $levOpt
        ]);
        $notionalMax = $marginUsdt * $levOpt;
        $contractsBud = $this->quantizeQty(
            $notionalMax / max(1e-12, $limit * $ctSize),
            max(1e-9, $qtyStep)
        );
        $contracts = (int)max(1, $contractsBud);

        // 8) (Optionnel) Garde liquidation locale (≥ 3× distance SL)
        try {
            $liqRatio = $this->estimateLiquidationDistanceRatio(
                entry: $limit,
                sl: $slQ,
                sideUpper: $side,
                leverage: (float)$levOpt,
                symbol: $symbol
            );
            if ($liqRatio < self::HC_MIN_LIQ_RATIO) {
                $this->positionsLogger->error('[HC] Liquidation guard KO', ['liq_ratio' => $liqRatio]);
                throw new \RuntimeException("[HC] liquidation ratio {$liqRatio} < " . self::HC_MIN_LIQ_RATIO);
            }
        } catch (\Throwable $e) {
            // Si tu préfères soft-fail, remplace par un warning :
            // $this->positionsLogger->warning('[HC] Liquidation guard check failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        // 9) Fixe le levier (côté exchange)
        $this->positionsLogger->info('[HC] Étape 9: Fixe levier (exchange)', ['lev' => (int)\ceil($levOpt)]);
        $this->bitmartPositions->setLeverage($symbol, (int)\ceil($levOpt), 'isolated');

        // 10) Soumission LIMIT + presets TP/SL
        $clientOrderId = 'HC_' . bin2hex(random_bytes(6));
        $payload = [
            'symbol'                        => $symbol,
            'client_order_id'               => $clientOrderId,
            'side'                          => $this->mapSideOpen(strtolower($side)),
            'mode'                          => 1,
            'type'                          => 'limit',
            'open_type'                     => 'isolated',
            'leverage'                      => (string)$levOpt,
            'size'                          => $contracts,
            'price'                         => (string)$limit,
            'preset_take_profit_price_type' => 2, // mark/fair
            'preset_stop_loss_price_type'   => 2,
            'preset_take_profit_price'      => (string)$tpQ,
            'preset_stop_loss_price'        => (string)$slQ,
            'stp_mode'                      => 1,
        ];

        $this->positionsLogger->info('[HC] Étape 10: Soumission ordre', $payload);
        $res = $this->ordersService->create($payload);
        if (($res['code'] ?? 0) !== 1000) {
            $this->positionsLogger->error('[HC] submit-order error', ['response' => $res]);
            throw new RuntimeException('submit-order error: ' . json_encode($res, JSON_UNESCAPED_SLASHES));
        }
        $orderId = $res['data']['order_id'] ?? null;

        // 11) Expiration auto (facultatif)
        if ($expireAfterSec !== null) {
            $this->positionsLogger->info('[HC] Étape 11: Expiration auto', ['expireAfterSec' => $expireAfterSec]);
            try { $this->scheduleCancelAllAfter($symbol, $expireAfterSec); }
            catch (\Throwable $e) {
                $this->positionsLogger->warning('[HC] scheduleCancelAllAfter failed', [
                    'symbol' => $symbol, 'timeout' => $expireAfterSec, 'error' => $e->getMessage()
                ]);
            }
        }

        // 12) Mise à jour du pipeline (optionnel)
        try {
            $this->pipelineRepository->updateStatusBySymbol(
                symbol: $symbol,
                status: ContractPipeline::STATUS_ORDER_OPENED
            );
            $this->positionsLogger->info('[HC] Pipeline -> STATUS_ORDER_OPENED', [
                'symbol' => $symbol, 'order_id' => $orderId
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->error('[HC] Pipeline update failed', [
                'symbol' => $symbol, 'error' => $e->getMessage()
            ]);
        }

        $out = [
            'symbol'     => $symbol,
            'side'       => $side,
            'order_id'   => $orderId,
            'client_order_id' => $clientOrderId,
            'limit'      => $limit,
            'sl'         => $slQ,
            'tp'         => $tpQ,
            'contracts'  => $contracts,
            'leverage'   => $levOpt,
            'metrics'    => $sr,
            'meta'       => array_merge($meta, [
                'high_conviction' => true,
                'leverage_cap'    => $leverageCap,
            ]),
        ];

        $this->positionsLogger->info('[HC] Retour final', $out);
        return $out;
    }

    /**
     * Estimation simple du ratio (distance_liquidation / distance_stop).
     * Implémente la vraie formule de ton exchange si tu veux être exact.
     */
    private function estimateLiquidationDistanceRatio(
        float $entry,
        float $sl,
        string $sideUpper,
        float $leverage,
        string $symbol
    ): float {
        // Approche conservative : liq ≈ entry * (1 ± 1/leverage) (simplifiée)
        $side = strtoupper($sideUpper);
        $liq  = ($side === 'LONG')
            ? $entry * (1.0 - (1.0 / max(1e-12, $leverage)))
            : $entry * (1.0 + (1.0 / max(1e-12, $leverage)));

        $distStop = abs($entry - $sl);
        $distLiq  = abs($entry - $liq);
        if ($distStop <= 0.0) {
            return INF;
        }
        return $distLiq / $distStop;
    }


    private function waitLeverageSynchronized(string $symbol, int $expected, string $openType = 'isolated', int $tries = 3, int $sleepMs = 150): void {
        for ($i = 0; $i < $tries; $i++) {
            try {
                $resp = $this->bitmartPositions->list(['symbol' => $symbol]);
                $data = $resp['data'] ?? [];
                $lev = (int)($data['leverage'] ?? ($data[0]['leverage'] ?? 0));
                if ($lev === $expected) return;
            } catch (\Throwable $e) {
                // ignore et retry
            }
            usleep($sleepMs * 1000);
        }
        // En cas d’incertitude, on retente submit-leverage puis on continue
        $this->setLeverage($symbol, $expected, $openType);
    }


}
