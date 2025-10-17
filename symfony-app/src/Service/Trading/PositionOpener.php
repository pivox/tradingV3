<?php
declare(strict_types=1);

namespace App\Service\Trading;

use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Config\TradingParameters;
use App\Service\Exception\Trade\Position\LeverageLowException;
use App\Service\Indicator\AtrCalculator;
use App\Service\Pipeline\MtfStateService;
use App\Service\Trading\Idempotency\ClientOrderIdFactory;
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
    private const LARGE_CAP_ASSETS                = ['BTC', 'ETH', 'BNB'];
    private const MIN_LEVERAGE_THRESHOLD          = 3.0;
    private const LARGE_CAP_MIN_LEVERAGE_RATIO    = 0.10; // 10% du levier max contrat

    private const SCALP_BASE_RISK_MAX_PCT         = 0.07; // 7% sur la marge engagée
    private const SCALP_DEFAULT_MARGIN            = 50.0; // budget usdt par défaut pour le mode scalp

    // --- Constantes spécifiques High Conviction ---
    private const HC_DEFAULT_LEV_CAP     = 50;   // levier max autorisé par la stratégie HC
    private const HC_MIN_LIQ_RATIO       = 3.0;  // liquidation ≥ 3x distance SL (si tu veux contrôler ici)
    private const HC_DEFAULT_R_MULTIPLE  = 2.0;  // TP à 2R (cohérent YAML v1.2)
    private const HC_DEFAULT_EXPIRE_SEC  = 120;  // annulation auto (2 minutes)

    public function __construct(
        private readonly AtrCalculator $atrCalculator,
        private readonly TradingParameters $tradingParameters,
        private readonly LoggerInterface $positionsLogger, // channel "positions"
        private readonly LoggerInterface $validationLogger, // channel "positions"
        private readonly MtfStateService $mtfState,
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
        private readonly OrdersService $ordersService,
        private readonly BitmartPositionsService $bitmartPositions,
        private readonly TrailOrdersService $trailOrders,
        private readonly BitmartHttpClientPublic $bitmartPublic,
        private readonly ClientOrderIdFactory $idempotency,
        private readonly SimpleQuantizer $quantizer,
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

        $orderId = null;

        try {
            /* ------------------ 0) Exposure guard ------------------ */
            $this->ensureNoActiveExposure($symbol, $side, '[Market]');

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
            $targetLev = (int)($maxLev * 0.2);
            $this->positionsLogger->info("Leverage calculation", [
                'lev_from_budget' => $levFromSizing,
                'lev_floor_2x'    => $levFloor,
                'max_lev_contract'=> $maxLev,
                'current_lev'     => $currentLev,
            ]);
            if ($levFromSizing < $targetLev) {
                $this->positionsLogger->info("Leverage calculation => no need to open leverage too low ");
                throw LeverageLowException::trigger($symbol, $maxLev,  $levFromSizing);
            }
            $factor     = $currentLev > 0 ? $targetLev / $currentLev : $targetLev;

            $this->positionsLogger->info('Leverage adjust', [
                'current' => $currentLev,
                'lev_from_sizing' => $levFromSizing,
                'target'  => $targetLev,
                'factor_vs_current' => $factor,
            ]);

            // (remplace appel direct -> fallback interne gérant 40012)
            $this->setLeverage($symbol, $targetLev, $openType);
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
        } finally {
            $this->persistOrderId($symbol, $orderId, '[Market]');
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
        $orderId = null;

        try {
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
            try { $this->setLeverage($symbol, $leverage, 'isolated'); } catch (\Throwable) {}

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


            /* 7) (Optionnel) Position TP/SL “plan_category=2” */
            // Si tu veux la couche position en plus des presets:
            try {
                $reduceSide = $this->mapSideReduce($side); // 3: close long | 2: close short
                $this->submitPositionTpSl(
                    symbol: $symbol, orderType: 'take_profit', sideReduce: $reduceSide,
                    triggerPrice: (string)$tpQ, priceType: 2, executivePrice: (string)$tpQ, category: 'limit'
                );
                $this->submitPositionTpSl(
                    symbol: $symbol, orderType: 'stop_loss', sideReduce: $reduceSide,
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
        } finally {
            $this->persistOrderId($symbol, $orderId, '[LimitPct]');
        }
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
        $orderId = null;

        try {
            $side = strtolower($finalSideUpper);

            // Vérification des positions existantes pour ce contrat
            $this->positionsLogger->info('[AutoLev] Vérification des positions existantes', ['symbol' => $symbol]);
            $existingPositions = $this->bitmartPositions->list(['symbol' => $symbol]);
            $positionsData = $existingPositions['data'] ?? [];
            
            // Vérifier s'il y a une position ouverte (size > 0)
            $hasOpenPosition = false;
            if (is_array($positionsData)) {
                foreach ($positionsData as $position) {
                    if (is_array($position)) {
                        $positionSize = (float)($position['size'] ?? 0);
                        if ($positionSize > 0) {
                            $hasOpenPosition = true;
                            $this->positionsLogger->warning('[AutoLev] Position existante détectée, abandon de l\'ouverture', [
                                'symbol' => $symbol,
                                'existing_size' => $positionSize,
                                'position_data' => $position
                            ]);
                            break;
                        }
                    }
                }
            }
            
            if ($hasOpenPosition) {
                throw new RuntimeException("Une position est déjà ouverte pour le contrat $symbol. Impossible d'ouvrir un nouvel ordre.");
            }

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
            $this->setLeverage($symbol, $leverage, 'isolated');
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
            ];
        } finally {
            $this->persistOrderId($symbol, $orderId, '[AutoLev]');
        }
    }


    /** Quantize une quantité au pas (arrondi vers le bas) */
    private function quantizeQty(float $v, float $step): float {
        $this->ensurePositive($step, 'qty_step');
        return floor($v / $step) * $step;
    }


    // ================= Exposure guards =================

    private function ensureNoActiveExposure(string $symbol, string $side, string $context): void
    {
        $existingPosition = $this->findActivePosition($symbol);
        if ($existingPosition !== null) {
            $this->positionsLogger->warning("$context Position already open, skip order", [
                'symbol' => $symbol,
                'side' => $side,
                'position' => $existingPosition,
            ]);
            throw new RuntimeException("Une position est déjà ouverte sur $symbol");
        }

        $existingOrder = $this->findPendingOrder($symbol);
        if ($existingOrder !== null) {
            $this->positionsLogger->warning("$context Order already pending, skip order", [
                'symbol' => $symbol,
                'side' => $side,
                'order' => $existingOrder,
            ]);
            throw new RuntimeException("Un ordre est déjà en attente sur $symbol");
        }
    }

    private function findActivePosition(string $symbol): ?array
    {
        try {
            $response = $this->bitmartPositions->list(['symbol' => $symbol]);
        } catch (Throwable $e) {
            $this->positionsLogger->warning('Position lookup failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $rows = $this->normalizePositionRows($response['data'] ?? []);
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $sym = strtoupper((string)($row['symbol'] ?? $row['contract_symbol'] ?? ''));
            if ($sym !== strtoupper($symbol)) {
                continue;
            }
            if ($this->extractPositionSize($row) > 0.0) {
                return $row;
            }
        }

        return null;
    }

    private function normalizePositionRows(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return [];
        }

        if (isset($payload['positions']) && \is_array($payload['positions'])) {
            return $this->normalizePositionRows($payload['positions']);
        }

        if (isset($payload['position']) && \is_array($payload['position'])) {
            return $this->normalizePositionRows($payload['position']);
        }

        if (isset($payload['data']) && \is_array($payload['data'])) {
            return $this->normalizePositionRows($payload['data']);
        }

        if (isset($payload['list']) && \is_array($payload['list'])) {
            return $this->normalizePositionRows($payload['list']);
        }

        if ($this->isList($payload)) {
            return array_values(array_filter($payload, static fn($row) => \is_array($row)));
        }

        return array_values(array_filter($payload, static fn($row) => \is_array($row)));
    }

    private function extractPositionSize(array $row): float
    {
        foreach (['size', 'hold_volume', 'volume'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = (float)$row[$key];
            if ($value > 0.0) {
                return $value;
            }
        }

        return 0.0;
    }

    private function findPendingOrder(string $symbol): ?array
    {
        try {
            $response = $this->ordersService->open(['symbol' => $symbol]);
        } catch (Throwable $e) {
            $this->positionsLogger->warning('Open orders lookup failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $orders = $this->normalizeOrdersPayload($response['orders'] ?? []);
        if ($orders !== []) {
            return $orders[0];
        }

        $planOrders = $this->normalizeOrdersPayload($response['plan_orders'] ?? []);
        if ($planOrders !== []) {
            return $planOrders[0];
        }

        return null;
    }

    private function normalizeOrdersPayload(mixed $payload): array
    {
        if (!\is_array($payload)) {
            return [];
        }

        if (isset($payload['order_list']) && \is_array($payload['order_list'])) {
            return array_values(array_filter($payload['order_list'], static fn($row) => \is_array($row)));
        }

        if (isset($payload['orders']) && \is_array($payload['orders'])) {
            return $this->normalizeOrdersPayload($payload['orders']);
        }

        if (isset($payload['data']) && \is_array($payload['data'])) {
            return $this->normalizeOrdersPayload($payload['data']);
        }

        if (isset($payload['list']) && \is_array($payload['list'])) {
            return $this->normalizeOrdersPayload($payload['list']);
        }

        if ($this->isList($payload)) {
            return array_values(array_filter($payload, static fn($row) => \is_array($row)));
        }

        return array_values(array_filter(
            $payload,
            static fn($row) => \is_array($row) && (
                isset($row['order_id'])
                || isset($row['client_order_id'])
                || isset($row['client_oid'])
            )
        ));
    }

    private function isList(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $expected = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
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
            // Ajouts pour réutiliser le dernier prix sans requête externe immédiate
            'last_price' => $contract->getLastPrice(),
            'index_price' => $contract->getIndexPrice(),
        ];
    }

    /** Mark price: tente d'utiliser d'abord les données locales Contract (last_price puis index_price)
     *  fallback sur l'appel Bitmart mark price kline si non disponible. */
    private function getMarkClose(string $symbol): float
    {
        // Nouvelle implémentation : on ignore les valeurs locales potentiellement anciennes
        // et on interroge directement l'API publique (mark price kline). On ne tombe en
        // fallback local qu'en ultime recours (et on log explicitement).
        try {
            $now = time();
            // On demande 2 points (sécurité) sur les ~2 dernières minutes
            $rows = $this->bitmartPublic->getMarkPriceKline(
                symbol: $symbol,
                step: 1,
                limit: 2,
                startTime: $now - 120,
                endTime: $now
            );
            if (!\is_array($rows) || empty($rows)) {
                throw new RuntimeException('markprice-kline: réponse vide');
            }
            $lastRow = end($rows);
            $close  = (float)($lastRow['close_price'] ?? 0.0);
            if ($close <= 0.0) {
                throw new RuntimeException('markprice-kline: close_price invalide');
            }
            return $close;
        } catch (Throwable $e) {
            $this->positionsLogger->warning('getMarkClose remote fetch failed, fallback local (deprecated path)', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            // Fallback minimal : on NE fait PAS confiance à l'ancien last_price pour une entrée précise.
            // On tente index_price; si absent on relance l'exception.
            try {
                $details = $this->getContractDetails($symbol);
                $index = (float)($details['index_price'] ?? 0.0);
                if ($index > 0.0) {
                    return $index;
                }
            } catch (Throwable $inner) {
                // ignore, on relèvera l'erreur initiale après
            }
            throw new RuntimeException('Impossible d\'obtenir un mark price frais pour ' . $symbol . ' : ' . $e->getMessage());
        }
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

    /** Règle le levier côté exchange (SIGNED) avec fallback code=40012 (margin mode non modifiable). */
    private function setLeverage(string $symbol, int $leverage, string $openType = 'isolated'): void
    {
        $this->positionsLogger->info('submit-leverage payload', [
            'symbol' => $symbol,
            'leverage' => $leverage,
            'open_type' => $openType,
        ]);

        try {
            $resp = $this->bitmartPositions->setLeverage($symbol, $leverage, $openType);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('submit-leverage transport exception (continue)', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return; // on continue malgré tout
        }

        $code = (int)($resp['code'] ?? 0);
        $this->positionsLogger->info('submit-leverage response', ['code' => $code, 'resp' => $resp]);

        if ($code === 1000) {
            return; // OK
        }

        if ($code === 40012) { // "There are currently positions or orders, the modification of margin mode is not supported"
            $this->positionsLogger->warning('submit-leverage margin mode change refused (40012) — tentative fallback', [
                'symbol' => $symbol,
                'requested_open_type' => $openType,
            ]);

            // On tente de détecter l'open_type actuel pour réessayer uniquement avec levier
            $existingType = null;
            try {
                $posResp = $this->bitmartPositions->list(['symbol' => $symbol]);
                $rows = $posResp['data'] ?? [];
                if (\is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!\is_array($row)) continue;
                        $sym = strtoupper((string)($row['symbol'] ?? ''));
                        if ($sym === strtoupper($symbol)) {
                            $existingType = $row['open_type'] ?? $row['margin_mode'] ?? $row['position_mode'] ?? null;
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->positionsLogger->warning('Impossible de récupérer les positions pour détecter open_type', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($existingType && $existingType !== $openType) {
                $this->positionsLogger->info('Retry submit-leverage avec open_type existant', [
                    'symbol' => $symbol,
                    'existing_open_type' => $existingType,
                ]);
                try {
                    $resp2 = $this->bitmartPositions->setLeverage($symbol, $leverage, (string)$existingType);
                    $code2 = (int)($resp2['code'] ?? 0);
                    $this->positionsLogger->info('submit-leverage fallback response', ['code' => $code2, 'resp' => $resp2]);
                    if ($code2 === 1000) {
                        return; // succès fallback
                    }
                } catch (\Throwable $e) {
                    $this->positionsLogger->warning('Fallback submit-leverage exception', [
                        'symbol' => $symbol,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // On continue sans lever d'exception : on utilisera le levier/mode existant.
            $this->positionsLogger->warning('Continuation sans modification de leverage/margin mode (code 40012)', [
                'symbol' => $symbol,
            ]);
            return;
        }

        // Autre code d'erreur: on lève pour diagnostic
        throw new RuntimeException('submit-leverage error: ' . json_encode($resp, JSON_UNESCAPED_SLASHES));
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

    public function openScalpTriggerOrder(
        string $symbol,
        string $finalSideUpper,
        array $triggerContext = []
    ): array {
        $overrides = $triggerContext['overrides'] ?? [];
        $conditions = $triggerContext['conditions'] ?? [];

        $margin = self::SCALP_DEFAULT_MARGIN;
        if (isset($overrides['margin_usdt']) && is_numeric($overrides['margin_usdt'])) {
            $margin = (float)$overrides['margin_usdt'];
        } elseif (isset($triggerContext['budget']) && is_numeric($triggerContext['budget'])) {
            $margin = (float)$triggerContext['budget'];
        }

        $leverageMultiplier = isset($overrides['leverage_multiplier']) ? (float)$overrides['leverage_multiplier'] : 1.0;
        if ($leverageMultiplier <= 0.0) {
            $leverageMultiplier = 1.0;
        }
        $riskMaxPct = max(0.01, self::SCALP_BASE_RISK_MAX_PCT * $leverageMultiplier);
        $rMultiple  = isset($overrides['tp_r_multiple']) ? (float)$overrides['tp_r_multiple'] : 2.0;
        $expireAfterSec = $this->convertDurationToSeconds($overrides['time_stop'] ?? null) ?? 120;

        $meta = [
            'mode' => 'scalp_trigger',
            'conditions' => $conditions,
        ];
        if (($triggerContext['meta'] ?? null) !== null) {
            $meta['meta'] = $triggerContext['meta'];
        }
        if ($overrides !== []) {
            $meta['overrides'] = $overrides;
        }

        $this->positionsLogger->info('Scalp trigger opening order', [
            'symbol' => $symbol,
            'side' => strtoupper($finalSideUpper),
            'margin_usdt' => $margin,
            'risk_max_pct' => $riskMaxPct,
            'r_multiple' => $rMultiple,
            'expire_after_sec' => $expireAfterSec,
        ]);

        return $this->openLimitAutoLevWithSr(
            symbol: $symbol,
            finalSideUpper: $finalSideUpper,
            marginUsdt: $margin,
            riskMaxPct: $riskMaxPct,
            rMultiple: $rMultiple,
            meta: $meta,
            expireAfterSec: $expireAfterSec,
        );
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
        $orderId = null;

        try {
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
        // FIX: Pour un ordre LIMIT, on doit orienter le prix dans le sens compatible avec l'échange.
        // LONG => prix <= mark (on arrondit vers le bas). SHORT => prix >= mark (on arrondit vers le haut).
        if (strtoupper($side) === 'LONG') {
            $limit = $this->quantizeDown($mark, $tick);
        } else {
            $limit = $this->quantizeUp($mark, $tick);
        }
        // Si pour une raison (mark exactement sur tick) limit == 0, fallback sur mark quantized neutre
        if ($limit <= 0) {
            $limit = $this->quantizeToStep($mark, $tick);
        }
        $this->positionsLogger->info('[SR] Prix d\'entrée directionnel', [
            'mark' => $mark,
            'limit' => $limit,
            'side' => $side,
            'tick' => $tick
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
        $stopPct = abs($slQ - $limit) / max(1e-12, $limit);

        $this->positionsLogger->info('[SR] Étape 6: Calcul du levier optimal', [
            'limit'      => $limit,
            'slQ'        => $slQ,
            'stop_pct'   => $stopPct,
            'atr'        => $sr['atr'],
            'riskMaxPct' => $riskMaxPct,
            'maxLev'     => $maxLev,
        ]);
        $levOptFloat = SrRiskHelper::leverageFromRisk($limit, $slQ, $riskMaxPct, $maxLev);
        $levFinal    = max(1, min($maxLev, (int)ceil($levOptFloat)));

        $sr['stop_pct'] = $stopPct;
        $sr['lev_opt']  = $levOptFloat;

        if ($levOptFloat <= self::MIN_LEVERAGE_THRESHOLD) {
            $this->positionsLogger->warning('[SR] Levier trop faible, abandon de l\'ouverture', [
                'symbol'     => $symbol,
                'lev_opt'    => $levOptFloat,
                'lev_final'  => $levFinal,
                'threshold'  => self::MIN_LEVERAGE_THRESHOLD,
                'stop_pct'   => $stopPct,
                'riskMaxPct' => $riskMaxPct,
            ]);
            throw LeverageLowException::trigger($symbol, $levOptFloat, self::MIN_LEVERAGE_THRESHOLD);
        }

        $baseAsset = substr(strtoupper($symbol), 0, 3);
        if (in_array($baseAsset, self::LARGE_CAP_ASSETS, true)) {
            $minLevFromMax = $maxLev * self::LARGE_CAP_MIN_LEVERAGE_RATIO;
            if ($levOptFloat < $minLevFromMax) {
                $this->positionsLogger->warning('[SR] Levier insuffisant pour large cap, abandon', [
                    'symbol'         => $symbol,
                    'base_asset'     => $baseAsset,
                    'lev_opt'        => $levOptFloat,
                    'lev_final'      => $levFinal,
                    'min_from_max'   => $minLevFromMax,
                    'contract_max'   => $maxLev,
                    'ratio_required' => self::LARGE_CAP_MIN_LEVERAGE_RATIO,
                ]);
                throw LeverageLowException::trigger($symbol, $levOptFloat, $minLevFromMax);
            }
        }


        $this->positionsLogger->info('[SR] Étape 7: Calcul du Take Profit (R-multiple)', [
            'rMultiple' => $rMultiple
        ]);
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
        // RETRY logique si erreur de prix limite rejeté par l'échange
        if ((int)($res['code'] ?? 0) !== 1000) {
            $rawMsg = json_encode($res, JSON_UNESCAPED_SLASHES);
            $this->positionsLogger->warning('[SR] submit-order rejet initial', ['response' => $res]);
            $adjusted = false;
            $allowedPrice = null;
            $constraintType = null; // 'max' | 'min'
            // Pattern: "should not exceed X" (borne max)
            if (preg_match('/should not exceed ([0-9.]+)/i', $rawMsg, $m)) {
                $allowedPrice = (float)$m[1];
                $constraintType = 'max';
                if ($limit > $allowedPrice) {
                    $adjusted = true;
                }
            } elseif (preg_match('/should not be lower than ([0-9.]+)/i', $rawMsg, $m)) {
                // Borne min
                $allowedPrice = (float)$m[1];
                $constraintType = 'min';
                if ($limit < $allowedPrice) {
                    $adjusted = true;
                }
            }
            if ($adjusted && $allowedPrice !== null) {
                $safetyTicks = 1; // marge de sécurité
                if ($constraintType === 'max') {
                    $proposed = $allowedPrice - $safetyTicks * $tick;
                    if ($proposed <= 0) { // garde-fou
                        $proposed = $allowedPrice; // on ne peut pas soustraire
                    }
                    $limit = $this->quantizeDown($proposed, $tick);
                } elseif ($constraintType === 'min') {
                    $proposed = $allowedPrice + $safetyTicks * $tick;
                    $limit = $this->quantizeUp($proposed, $tick);
                }
                // Assure sens logique LONG/SHORT (optionnel, sans rejet si diff minime)
                if ($side === 'LONG' && $limit > $mark) {
                    $limit = $this->quantizeDown(min($limit, $mark), $tick);
                } elseif ($side === 'SHORT' && $limit < $mark) {
                    $limit = $this->quantizeUp(max($limit, $mark), $tick);
                }
                $payload['price'] = (string)$limit;
                $this->positionsLogger->info('[SR] Réajustement du prix limite avec marge de sécurité', [
                    'constraint' => $constraintType,
                    'allowed_price' => $allowedPrice,
                    'new_limit' => $limit,
                    'tick' => $tick,
                    'safety_ticks' => $safetyTicks,
                ]);
                $res = $this->ordersService->create($payload);
            }
        }
        // Mise à jour de l'état MTF
        try {
            $eventId = $this->buildEventId('ORDER_PLACED', $symbol);
            $this->mtfState->applyOrderPlaced($eventId, $symbol, [$timeframe ?? '5m']);
            if (!empty($res['data']['order_id'])) {
                $intent = $side === 'LONG' ? 'OPEN_LONG' : 'OPEN_SHORT';
                $this->mtfState->recordOrder((string)$res['data']['order_id'], $symbol, $intent);
            }
            $this->positionsLogger->info('[SR] État MTF mis à jour -> ORDER_PLACED', [
                'symbol' => $symbol,
                'order_id' => $res['data']['order_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->error('[SR] Erreur lors de la mise à jour MTF', [
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
        } finally {
            $this->persistOrderId($symbol, $orderId, '[SR]');
        }
    }


    /**
     * Variante HIGH CONVICTION :
     * - Même logique S/R + ATR que openLimitAutoLevWithSr()
     * - Levier borné par $leverageCap (sans augmenter le risk %)
     * - Garde le sizing budget→notional, puis quantize au pas d’échange
     */
    public function openLimitHighConvWithSr(
        string $symbol,
        string $finalSideUpper,            // 'LONG' | 'SHORT'
        int    $leverageCap    = self::HC_DEFAULT_LEV_CAP,
        float  $marginUsdt     = 60.0,
        ?float $riskMaxPct     = null,     // si null => YAML risk.fixed_risk_pct
        ?float $rMultiple      = null,     // si null => YAML atr.r_multiple ou long.take_profit.tp1_r
        array  $meta           = [],
        ?int   $expireAfterSec = self::HC_DEFAULT_EXPIRE_SEC
    ): array {
        $orderId       = null;
        $clientOrderId = 'HC_' . bin2hex(random_bytes(6));
        $contextTrail  = [];
        $sideUpper     = strtoupper($finalSideUpper);
        $sideLower     = strtolower($finalSideUpper); // 'long'|'short'
        $executionTf   = strtolower((string)($meta['timeframe'] ?? '5m'));

        // ==== Charger toute la config (YAML + overrides DB) ====
        $params = $this->tradingParameters->all();

        // Helpers sûrs pour lire l’array config
        $aget = static function(array $a, string $path, $default = null) {
            $cur = $a;
            foreach (explode('.', $path) as $key) {
                if (!\is_array($cur) || !\array_key_exists($key, $cur)) {
                    return $default;
                }
                $cur = $cur[$key];
            }
            return $cur;
        };

        // ---- Risque & R multiple depuis YAML (fallbacks) ----
        // risk.fixed_risk_pct (en %) -> fraction (0..1)
        $riskPctYaml = (float)$aget($params, 'risk.fixed_risk_pct', 5.0) / 100.0;
        $riskPct     = $riskMaxPct !== null ? (float)$riskMaxPct : $riskPctYaml;

        // Si une valeur absolue override existe :
        $riskAbsYaml = $aget($params, 'risk.abs_usdt', null);
        $riskAbsExec = $aget($params, 'execution.risk_abs_usdt', null);
        $riskAbsUsdt = null;
        if (\is_numeric($riskAbsExec)) {
            $riskAbsUsdt = (float)$riskAbsExec;
        } elseif (\is_numeric($riskAbsYaml)) {
            $riskAbsUsdt = (float)$riskAbsYaml;
        }
        // rMultiple : priorité atr.r_multiple, sinon long.take_profit.tp1_r, sinon param reçu, sinon 2.0
        $rMultipleYaml = $aget($params, 'atr.r_multiple', null);
        if (!\is_numeric($rMultipleYaml)) {
            $rMultipleYaml = $aget($params, 'long.take_profit.tp1_r', 2.0);
        }
        $rMultiple = $rMultiple !== null ? (float)$rMultiple : (float)$rMultipleYaml;

        // ATR params
        $atrTimeframe = (string)$aget($params, 'atr.timeframe', '5m');
        $atrPeriod    = (int)$aget($params, 'atr.period', 14);
        $atrMethod    = (string)$aget($params, 'atr.method', 'wilder'); // 'wilder'|'simple'
        $slMult       = (float)$aget($params, 'atr.sl_multiplier', 1.5);

        try {
            // ========== Étape 1: Détails contrat ==========
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
            $contextTrail[] = ['step' => 'contract_details', 'tick' => $tick, 'qty_step' => $qtyStep, 'ct_size' => $ctSize, 'maxLev' => $maxLev];

            // ========== Étape 2: Prix mark & LIMIT proche ==========
            $mark = $this->getMarkPrice($symbol);
            if ($mark <= 0) {
                throw new \RuntimeException("Invalid mark price for $symbol");
            }
            $slippageTicks = 1;
            $limit = $this->quantizeToStep($mark, $tick);
            if ($sideLower === 'long') {
                $limit = $this->quantizeToStep($limit - $slippageTicks * $tick, $tick);
            } else {
                $limit = $this->quantizeToStep($limit + $slippageTicks * $tick, $tick);
            }
            $contextTrail[] = ['step' => 'pricing', 'mark' => $mark, 'limit' => $limit];

            // ========== Étape 3: ATR depuis YAML ==========
            $this->positionsLogger->info('[HC] Étape 3: ATR source', [
                'timeframe' => $atrTimeframe, 'period' => $atrPeriod, 'method' => $atrMethod
            ]);
            $atrSeries = $this->klineRepository->findLastKlines(
                symbol: $symbol, timeframe: $atrTimeframe, limit: max($atrPeriod + 1, 200)
            );
            if (\count($atrSeries) <= $atrPeriod) {
                throw new \RuntimeException("Not enough OHLC for ATR ($symbol tf=$atrTimeframe)");
            }
            $atr = $this->atrCalculator->compute($atrSeries, $atrPeriod, $atrMethod);
            $contextTrail[] = ['step' => 'atr', 'atr' => $atr, 'tf' => $atrTimeframe, 'period' => $atrPeriod];

            // ========== Étape 4: S/R & SL basé S/R ± k*ATR ==========
            $this->positionsLogger->info('[HC] Étape 4: Détection S/R', ['symbol' => $symbol]);
            $klines15m = $this->klineRepository->findLastKlines(symbol: $symbol, timeframe: '15m', limit: 200);
            if (\count($klines15m) < 50) {
                throw new \RuntimeException("Not enough klines for SR detection ($symbol)");
            }
            $sr = \App\Util\SrRiskHelper::findSupportResistance($klines15m);

            $kAtr = $slMult; // cohérent avec atr.sl_multiplier
            if ($sideLower === 'long') {
                $slRaw = max($sr['support'] ?? ($mark - $kAtr * $atr), $mark - 5 * $atr);
            } else {
                $slRaw = min($sr['resistance'] ?? ($mark + $kAtr * $atr), $mark + 5 * $atr);
            }
            $slQ = $this->quantizeToStep($slRaw, $tick);
            $contextTrail[] = ['step' => 'sr_stop', 'sr' => $sr, 'sl' => $slQ];

            // ========== Étape 5: Sizing & risque ==========
            // Montant risqué : priorité au risque absolu si défini, sinon % de la marge
            $riskAbsEffective = $riskAbsUsdt !== null ? (float)$riskAbsUsdt : max(0.0, $marginUsdt * $riskPct);

            // Taille (contrats) en respectant levier & pas de qty
            // notional ≈ qty * (price * contract_size)
            $contracts = (int)max(1, floor(($marginUsdt * $leverageCap) / ($limit * $ctSize)));
            if ($qtyStep > 0.0) {
                $contracts = (int)max(1, floor($contracts / $qtyStep) * $qtyStep);
            }

            $stopDist = abs($limit - $slQ);
            if ($stopDist <= 0.0) {
                throw new \RuntimeException('Stop distance is zero/negative');
            }

            // TP à R-multiple
            $tpQ = $this->computeTpPrice($sideLower, $limit, $stopDist, $rMultiple);
            $tpQ = $this->quantizeToStep($tpQ, $tick);

            $contextTrail[] = [
                'step'       => 'sizing',
                'contracts'  => $contracts,
                'risk_usdt'  => $riskAbsEffective,
                'risk_pct'   => $riskPct,
                'stop_dist'  => $stopDist,
                'tp'         => $tpQ,
            ];

            // ========== Étape 6: Levier final & sync exchange ==========
            $levOpt = (int)min($leverageCap, $maxLev);
            $openType = 'isolated'; // lis depuis YAML plus tard si tu veux le rendre dynamique
            $this->positionsLogger->info('[HC] Étape 6: Fixe levier côté exchange', ['lev' => $levOpt, 'open_type' => $openType]);

            $this->bitmartPositions->setLeverage($symbol, $levOpt, $openType);  // POST /submit-leverage
            $this->waitLeverageSynchronized($symbol, $levOpt, $openType, tries: 5, sleepMs: 250);
            $contextTrail[] = ['step' => 'leverage_synced', 'lev' => $levOpt, 'openType' => $openType];

            // ========== Étape 7: Payload LIMIT + presets (mark price) ==========
            $payload = [
                'symbol'                        => $symbol,
                'client_order_id'               => $clientOrderId,
                'side'                          => $this->mapSideOpen($sideLower), // buy/sell
                'mode'                          => 1,            // one-way
                'type'                          => 'limit',
                'open_type'                     => $openType,
                'leverage'                      => (string)$levOpt,
                'size'                          => $contracts,
                'price'                         => (string)$limit,
                'preset_take_profit_price_type' => 2,            // 2 = mark
                'preset_stop_loss_price_type'   => 2,            // 2 = mark
                'preset_take_profit_price'      => (string)$tpQ,
                'preset_stop_loss_price'        => (string)$slQ,
                'stp_mode'                      => 1,
            ];

            // Respect éventuel de order_plan.routing.post_only
            $postOnly = (bool)$aget($params, 'order_plan.routing.post_only', true);
            if ($postOnly === true) {
                $payload['force'] = 'post_only';
            }

            $this->positionsLogger->info('[HC] Étape 7: Payload ordre', $payload);

            // ========== Étape 8: Submit + retry contrôlé 40012 ==========
            $submitOnce = function(array $p): array { return $this->ordersService->create($p); };

            $res = $submitOnce($payload);
            if ((int)($res['code'] ?? 0) !== 1000) {
                $msg = json_encode($res, JSON_UNESCAPED_SLASHES);
                $this->positionsLogger->warning('[HC] submit-order non 1000', ['response' => $res]);

                // 40012: leverage not synchronized
                if (strpos($msg, '"code":40012') !== false || strpos($msg, 'Leverage info not synchronized') !== false) {
                    $this->positionsLogger->warning('[HC] 40012 → Re-sync levier + backoff + 2e tentative', [
                        'symbol' => $symbol, 'lev' => $levOpt
                    ]);
                    $this->bitmartPositions->setLeverage($symbol, $levOpt, $openType);
                    $this->waitLeverageSynchronized($symbol, $levOpt, $openType, tries: 3, sleepMs: 300);
                    usleep(200_000);

                    $res = $submitOnce($payload);
                    if ((int)($res['code'] ?? 0) !== 1000) {
                        $this->positionsLogger->error('[HC] submit-order error après retry', ['response' => $res]);
                        throw new \RuntimeException('submit-order error: ' . json_encode($res, JSON_UNESCAPED_SLASHES));
                    }
                } else {
                    throw new \RuntimeException('submit-order error: ' . $msg);
                }
            }

            $orderId = $res['data']['order_id'] ?? null;

            // ========== Étape 9: Expiration auto ==========
            if ($expireAfterSec !== null) {
                $this->positionsLogger->info('[HC] Étape 9: Expiration auto', ['expireAfterSec' => $expireAfterSec]);
                try { $this->scheduleCancelAllAfter($symbol, $expireAfterSec); }
                catch (\Throwable $e) {
                    $this->positionsLogger->warning('[HC] scheduleCancelAllAfter erreur (ignorée)', ['error' => $e->getMessage()]);
                }
            }

            // ========== OK ==========
            $contextTrail[] = ['step' => 'order_submitted', 'order_id' => $orderId, 'client_order_id' => $clientOrderId];
            $this->positionsLogger->info('[HC] Order submitted', [
                'symbol' => $symbol, 'order_id' => $orderId, 'client_order_id' => $clientOrderId, 'trail' => $contextTrail
            ]);

            return [
                'success'          => true,
                'order_id'         => $orderId,
                'client_order_id'  => $clientOrderId,
                'payload'          => $payload,
                'context_trail'    => $contextTrail,
                'meta'             => $meta,
            ];
        } catch (\Throwable $e) {
            $contextTrail[] = [
                'step'           => 'order_failed',
                'type'           => 'high_conviction',
                'error'          => $e->getMessage(),
                'available_usdt' =>  null,
                'margin_usdt'    => $marginUsdt
            ];
            $this->validationLogger->error('Order submission failed [HIGH_CONVICTION]', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
                'trail'  => $contextTrail
            ]);

            return [
                'success'       => false,
                'error'         => $e->getMessage(),
                'context_trail' => $contextTrail,
            ];
        }
    }



    private function persistOrderId(string $symbol, string|int|null $orderId, string $context): void
    {
        if ($orderId === null) {
            return;
        }

        $orderIdAsString = trim((string)$orderId);
        if ($orderIdAsString === '') {
            return;
        }

        $intent = str_contains(strtolower($context), 'short') ? 'OPEN_SHORT' : 'OPEN_LONG';
        try {
            $this->mtfState->recordOrder($orderIdAsString, $symbol, $intent);
            $this->positionsLogger->info("$context OrderId persisted in mtf", [
                'symbol' => $symbol,
                'order_id' => $orderIdAsString,
            ]);
        } catch (Throwable $error) {
            $this->positionsLogger->warning("$context Failed to persist orderId", [
                'symbol' => $symbol,
                'order_id' => $orderIdAsString,
                'error' => $error->getMessage(),
            ]);
        }
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


    private function waitLeverageSynchronized(string $symbol, int $expected, string $openType = 'isolated', int $tries = 5, int $sleepMs = 200): void {
        $this->positionsLogger->info('[LEVERAGE_SYNC] Attente de la synchronisation du levier', [
            'symbol' => $symbol,
            'expected' => $expected,
            'tries' => $tries,
            'sleep_ms' => $sleepMs
        ]);

        for ($i = 0; $i < $tries; $i++) {
            try {
                $resp = $this->bitmartPositions->list(['symbol' => $symbol]);
                $data = $resp['data'] ?? [];
                $lev = (int)($data['leverage'] ?? ($data[0]['leverage'] ?? 0));

                $this->positionsLogger->info('[LEVERAGE_SYNC] Tentative ' . ($i + 1) . '/' . $tries, [
                    'symbol' => $symbol,
                    'current_leverage' => $lev,
                    'expected' => $expected,
                    'synced' => $lev === $expected
                ]);

                if ($lev === $expected) {
                    $this->positionsLogger->info('[LEVERAGE_SYNC] Levier synchronisé avec succès', [
                        'symbol' => $symbol,
                        'leverage' => $lev,
                        'attempts' => $i + 1
                    ]);
                    return;
                }
            } catch (\Throwable $e) {
                $this->positionsLogger->warning('[LEVERAGE_SYNC] Erreur lors de la vérification', [
                    'symbol' => $symbol,
                    'attempt' => $i + 1,
                    'error' => $e->getMessage()
                ]);
            }
            usleep($sleepMs * 1000);
        }

        // En cas d'incertitude, on retente submit-leverage puis on continue
        $this->positionsLogger->warning('[LEVERAGE_SYNC] Levier non synchronisé après ' . $tries . ' tentatives, re-soumission', [
            'symbol' => $symbol,
            'expected' => $expected
        ]);
        $this->setLeverage($symbol, $expected, $openType);

        // Dernière vérification après re-soumission
        usleep($sleepMs * 2000);
        try {
            $resp = $this->bitmartPositions->list(['symbol' => $symbol]);
            $data = $resp['data'] ?? [];
            $lev = (int)($data['leverage'] ?? ($data[0]['leverage'] ?? 0));
            $this->positionsLogger->info('[LEVERAGE_SYNC] État final après re-soumission', [
                'symbol' => $symbol,
                'final_leverage' => $lev,
                'expected' => $expected,
                'synced' => $lev === $expected
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->error('[LEVERAGE_SYNC] Impossible de vérifier l\'état final', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function buildEventId(string $type, string $symbol): string
    {
        return sprintf('%s|%s|%d', $type, strtoupper($symbol), (int)(microtime(true) * 1000));
    }

    private function convertDurationToSeconds(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (is_numeric($normalized)) {
            $seconds = (int)round((float)$normalized);
            return $seconds > 0 ? $seconds : null;
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)([smhd])$/', $normalized, $matches)) {
            return null;
        }

        $amount = (float)$matches[1];
        $unit = $matches[2];

        $seconds = match ($unit) {
            's' => $amount,
            'm' => $amount * 60,
            'h' => $amount * 3600,
            'd' => $amount * 86400,
            default => null,
        };

        if ($seconds === null) {
            return null;
        }

        return (int)max(1, round($seconds));
    }

    /** BC alias: certains appels utilisent encore getMarkPrice() */
    private function getMarkPrice(string $symbol): float
    {
        return $this->getMarkClose($symbol);
    }

    /**
     * Enveloppe conviviale pour soumettre un TP/SL "position" (plan_category=2) avec fallback.
     * $orderType: 'take_profit' | 'stop_loss'
     * $sideReduce: 3 = close long (sell), 2 = close short (buy)  -> cf. mapSideReduce()
     * $priceType:  2 = mark/fair (recommandé), 1 = last_price
     * $category:   'market' (par défaut) ou 'limit'
     *
     * Retourne la réponse finale du submit (celle qui a réussi après fallback).
     */
    private function submitPositionTpSl(
        string $symbol,
        string $orderType,
        int    $sideReduce,
        string $triggerPrice,
        int    $priceType = 2,
        string $executivePrice = '0',
        string $category = 'market',
        ?int   $size = null,
        ?int   $planCategory = 2   // 2 = TP/SL de position
    ): array {
        // Récupère les pas & tailles pour quantiser size si besoin
        $details = $this->getContractDetails($symbol);
        $qtyStep = (float)($details['vol_precision'] ?? 0.0);
        $ctSize  = (float)($details['contract_size'] ?? 0.0);
        $size    = $size ?? (int)max(1, $qtyStep > 0.0 ? $qtyStep : 1);

        // Normalise les prix (stringifiés) -> build du payload
        $triggerQ = (float)$triggerPrice;
        $execQ    = (float)$executivePrice;

        $payload = $this->buildPlanPayload(
            symbol: $symbol,
            sideReduce: $sideReduce,
            type: $orderType,      // 'stop_loss' | 'take_profit'
            size: $size,
            triggerQ: $triggerQ,
            execQ: $execQ,
            priceType: $priceType, // 2 = mark
            planCategory: $planCategory,
            category: $category    // 'market' (par défaut) avec fallback vers 'limit'
        );

        // Envoi avec fallback intégré (market→limit→price_type→sans plan_category)
        return $this->submitPlanOrderWithFallback($payload);
    }
// PSEUDO-CODE (Symfony service)
// Remplace intégralement ta méthode existante par celle-ci

    public function enforcePositionTpSl(array $position): void
    {
        $symbol = (string)($position['symbol'] ?? '');
        $entry  = (float)($position['entry_price'] ?? 0.0);
        $side   = strtolower((string)($position['side'] ?? 'long'));   // 'long'|'short'
        $size   = (int)($position['size'] ?? 0);                       // nb de contrats

        if ($symbol === '' || $entry <= 0.0 || !\in_array($side, ['long','short'], true)) {
            $this->positionsLogger->warning('[TP/SL] Position invalide', compact('symbol','entry','side','size'));
            return;
        }

        // 1) Détails contrat (pas & tailles)
        $details = $this->getContractDetails($symbol);
        $tick    = (float)($details['price_precision'] ?? 0.0);
        $ctSize  = (float)($details['contract_size']   ?? 0.0);
        $qtyStep = (float)($details['vol_precision']   ?? 0.0);

        if ($tick <= 0.0 || $ctSize <= 0.0) {
            $this->positionsLogger->error('[TP/SL] Détails contrat invalides', compact('tick','ctSize','qtyStep'));
            return;
        }
        if ($size <= 0) {
            // On tente une taille minimale si absente (garantit un size > 0 pour plan_category=2 si requis)
            $size = (int)max(1, $qtyStep > 0.0 ? $qtyStep : 1);
        }

        // 2) Source de TP/SL : d'abord la position (si déjà calculés), sinon fallback config (abs USDT)
        $tpFromPos = isset($position['tp_price']) ? (float)$position['tp_price'] : null;
        $slFromPos = isset($position['sl_price']) ? (float)$position['sl_price'] : null;

        $tpQ = null; $slQ = null;
        if (is_finite((float)$tpFromPos) && (float)$tpFromPos > 0.0 &&
            is_finite((float)$slFromPos) && (float)$slFromPos > 0.0) {
            // Utilise les prix fournis par la position
            $tpQ = $this->quantizeToStep((float)$tpFromPos, $tick);
            $slQ = $this->quantizeToStep((float)$slFromPos, $tick);
        } else {
            // Fallback : calcule TP/SL à partir de la conf (tp.abs_usdt, risk.abs_usdt) et de la taille
            $cfg        = $this->tradingParameters->all();
            $tpAbsUsdt  = (float)($cfg['tp']['abs_usdt']   ?? 5.0);
            $slAbsUsdt  = (float)($cfg['risk']['abs_usdt'] ?? 3.0);

            if ($tpAbsUsdt <= 0.0 || $slAbsUsdt <= 0.0) {
                $this->positionsLogger->warning('[TP/SL] Config abs_usdt invalide', compact('tpAbsUsdt','slAbsUsdt'));
                return;
            }

            $qtyNotional = max(1e-9, $size * $ctSize);

            if ($side === 'long') {
                $slRaw = $entry - ($slAbsUsdt / $qtyNotional);
                $tpRaw = $entry + ($tpAbsUsdt / $qtyNotional);
            } else {
                $slRaw = $entry + ($slAbsUsdt / $qtyNotional);
                $tpRaw = $entry - ($tpAbsUsdt / $qtyNotional);
            }

            $tpQ = $this->quantizeToStep($tpRaw, $tick);
            $slQ = $this->quantizeToStep($slRaw, $tick);

            $this->positionsLogger->info('[TP/SL] Fallback abs_usdt appliqué', [
                'tp_abs_usdt' => $tpAbsUsdt,
                'sl_abs_usdt' => $slAbsUsdt,
                'qty_notional'=> $qtyNotional,
                'tp_q' => $tpQ, 'sl_q' => $slQ
            ]);
        }

        // 3) Garde-fous directionnels
        if ($side === 'long' && !($slQ < $entry && $tpQ > $entry)) {
            $this->positionsLogger->warning('[TP/SL] Sens invalide LONG', ['entry'=>$entry,'tp'=>$tpQ,'sl'=>$slQ]);
            return;
        }
        if ($side === 'short' && !($slQ > $entry && $tpQ < $entry)) {
            $this->positionsLogger->warning('[TP/SL] Sens invalide SHORT', ['entry'=>$entry,'tp'=>$tpQ,'sl'=>$slQ]);
            return;
        }

        // 4) Soumission en "Position TP/SL" (plan_category=2), price_type=2 (mark), catégorie 'market'
        $reduceSide = $this->mapSideReduce($side); // 3: close long | 2: close short

        try {
            $this->positionsLogger->info('[TP/SL] Submit TAKE_PROFIT (plan_category=2)', [
                'symbol'=>$symbol,'side'=>$side,'trigger'=>$tpQ
            ]);
            $tpRes = $this->submitPositionTpSl(
                symbol: $symbol,
                orderType: 'take_profit',
                sideReduce: $reduceSide,
                triggerPrice: (string)$tpQ,
                priceType: 2,               // 2 = fair/mark
                executivePrice: '0',        // inutile pour 'market'
                category: 'market',
                size: $size,
                planCategory: 2
            );

            $this->positionsLogger->info('[TP/SL] Submit STOP_LOSS (plan_category=2)', [
                'symbol'=>$symbol,'side'=>$side,'trigger'=>$slQ
            ]);
            $slRes = $this->submitPositionTpSl(
                symbol: $symbol,
                orderType: 'stop_loss',
                sideReduce: $reduceSide,
                triggerPrice: (string)$slQ,
                priceType: 2,
                executivePrice: '0',
                category: 'market',
                size: $size,
                planCategory: 2
            );

            $this->positionsLogger->info('[TP/SL] Enforcement done', [
                'symbol' => $symbol,
                'entry'  => $entry,
                'tp_q'   => $tpQ,
                'sl_q'   => $slQ,
                'tp_res' => $tpRes,
                'sl_res' => $slRes,
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->error('[TP/SL] Submission failed', [
                'symbol' => $symbol,
                'error'  => $e->getMessage(),
            ]);
        }
    }


}
