<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Service;

use App\Domain\PostValidation\Dto\EntryZoneDto;
use App\Domain\PostValidation\Dto\MarketDataDto;
use App\Domain\PostValidation\Dto\OrderPlanDto;
use App\Config\TradingParameters;
use App\Infrastructure\Http\BitmartRestClient;
use Psr\Log\LoggerInterface;

/**
 * Service d'ouverture de position avec sizing, levier et plan d'ordres
 * 
 * Gère :
 * - Sizing basé sur le risque et ATR
 * - Calcul du levier avec respect des brackets
 * - Plan d'ordres (maker -> taker fallback)
 * - TP/SL attachés
 * - Idempotence et corrélation
 */
final class PositionOpener
{
    public function __construct(
        private readonly TradingParameters $config,
        private readonly BitmartRestClient $restClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Crée un plan d'ordres pour l'ouverture de position
     */
    public function createOrderPlan(
        EntryZoneDto $entryZone,
        MarketDataDto $marketData,
        string $executionTimeframe,
        array $mtfContext,
        float $walletEquity
    ): OrderPlanDto {
        $this->logger->info('[PositionOpener] Creating order plan', [
            'symbol' => $entryZone->symbol,
            'side' => $entryZone->side,
            'timeframe' => $executionTimeframe
        ]);

        // 1. Calcul du sizing
        $sizingData = $this->calculateSizing($entryZone, $marketData, $walletEquity);
        
        // 2. Calcul du levier
        $leverageData = $this->calculateLeverage($sizingData, $marketData, $mtfContext);
        
        // 3. Génération des ordres maker
        $makerOrders = $this->generateMakerOrders($entryZone, $sizingData, $leverageData);
        
        // 4. Génération des ordres fallback
        $fallbackOrders = $this->generateFallbackOrders($entryZone, $sizingData, $leverageData);
        
        // 5. Génération des ordres TP/SL
        $tpSlOrders = $this->generateTpSlOrders($entryZone, $sizingData, $leverageData);
        
        // 6. Génération des IDs et clés
        $ids = $this->generateIds($entryZone, $executionTimeframe, $mtfContext);
        
        // 7. Métriques de risque
        $riskMetrics = $this->calculateRiskMetrics($sizingData, $leverageData, $entryZone);
        
        // 8. Preuves
        $evidence = $this->buildEvidence($sizingData, $leverageData, $marketData, $mtfContext);

        $orderPlan = new OrderPlanDto(
            symbol: $entryZone->symbol,
            side: $entryZone->side,
            executionTimeframe: $executionTimeframe,
            quantity: $sizingData['quantity'],
            leverage: $leverageData['final_leverage'],
            makerOrders: $makerOrders,
            fallbackOrders: $fallbackOrders,
            tpSlOrders: $tpSlOrders,
            clientOrderId: $ids['client_order_id'],
            decisionKey: $ids['decision_key'],
            riskMetrics: $riskMetrics,
            evidence: $evidence,
            timestamp: time()
        );

        $this->logger->info('[PositionOpener] Order plan created', [
            'symbol' => $entryZone->symbol,
            'side' => $entryZone->side,
            'quantity' => $sizingData['quantity'],
            'leverage' => $leverageData['final_leverage'],
            'risk_amount' => $riskMetrics['risk_amount']
        ]);

        return $orderPlan;
    }

    /**
     * Calcule le sizing basé sur le risque et ATR
     */
    private function calculateSizing(
        EntryZoneDto $entryZone,
        MarketDataDto $marketData,
        float $walletEquity
    ): array {
        $config = $this->getSizingConfig();
        $riskPct = $config['risk_pct'] ?? 0.005; // 0.5% par défaut
        $slMult = $config['sl_mult_atr'] ?? 1.5;
        
        // Taille notionnelle à risque
        $notional = min($config['budget_usdt'] ?? 100, $walletEquity);
        $riskUnit = $riskPct * $walletEquity;
        
        // Distance de stop basée sur ATR
        $atrValue = $entryZone->atrValue;
        $distanceStop = $slMult * $atrValue;
        
        // Éviter la division par zéro
        if ($distanceStop <= 0) {
            $distanceStop = $entryZone->getMidPrice() * 0.01; // 1% par défaut
        }
        
        // Quantité basée sur le risque
        $contractValueStep = $marketData->contractDetails['lot_size'] ?? 0.001;
        $quantity = floor($riskUnit / $distanceStop / $contractValueStep) * $contractValueStep;
        
        // Quantification au lot
        $quantity = $this->quantizeQuantity($quantity, $marketData->contractDetails);

        return [
            'notional' => $notional,
            'risk_unit' => $riskUnit,
            'distance_stop' => $distanceStop,
            'quantity' => $quantity,
            'risk_pct' => $riskPct,
            'sl_mult' => $slMult
        ];
    }

    /**
     * Calcule le levier avec respect des brackets
     */
    private function calculateLeverage(
        array $sizingData,
        MarketDataDto $marketData,
        array $mtfContext
    ): array {
        $config = $this->getLeverageConfig();
        $leverageBracket = $marketData->leverageBracket;
        
        // Levier demandé basé sur le risque
        $requestedLeverage = $sizingData['notional'] / $sizingData['risk_unit'];
        
        // Appliquer les multiplicateurs par timeframe
        $tfMultiplier = $this->getTimeframeMultiplier($config, $mtfContext);
        $requestedLeverage *= $tfMultiplier;
        
        // Appliquer le multiplicateur de conviction si applicable
        $convictionMultiplier = $this->getConvictionMultiplier($mtfContext, $config);
        $requestedLeverage *= $convictionMultiplier;
        
        // Clamp par les limites de l'exchange
        $maxLeverage = $this->getMaxLeverageFromBracket($leverageBracket);
        $finalLeverage = min($requestedLeverage, $maxLeverage);
        
        // Clamp par les caps par symbole
        $symbolCap = $this->getSymbolCap($marketData->symbol, $config);
        $finalLeverage = min($finalLeverage, $symbolCap);
        
        // Arrondi
        $finalLeverage = round($finalLeverage, 2);

        return [
            'requested_leverage' => $requestedLeverage,
            'tf_multiplier' => $tfMultiplier,
            'conviction_multiplier' => $convictionMultiplier,
            'max_leverage_bracket' => $maxLeverage,
            'symbol_cap' => $symbolCap,
            'final_leverage' => $finalLeverage
        ];
    }

    /**
     * Génère les ordres maker (LIMIT GTC)
     */
    private function generateMakerOrders(
        EntryZoneDto $entryZone,
        array $sizingData,
        array $leverageData
    ): array {
        $config = $this->getOrderPlanConfig();
        $makerConfig = $config['maker'] ?? [];
        
        $orders = [];
        $quantity = $sizingData['quantity'];
        $side = $entryZone->side;
        
        // Split 0.4 / 0.4 / 0.2 si l'entrée est valide
        $fractions = $entryZone->isValidEntry ? [0.4, 0.4, 0.2] : [1.0];
        $prices = [];
        $mid = $entryZone->getMidPrice();
        // répartir les prix au sein de la zone [entryMin, entryMax]
        if (count($fractions) === 3) {
            $prices = [
                $entryZone->entryMin,
                $mid,
                $entryZone->entryMax,
            ];
        } else {
            $prices = [$mid];
        }

        $orderType = $side === 'LONG' ? 'buy_open_long' : 'sell_open_short';
        foreach ($fractions as $idx => $f) {
            $qtyPart = max(0.0, $quantity * $f);
            if ($qtyPart <= 0.0) continue;
            $price = $prices[min($idx, count($prices)-1)];
            $orders[] = [
                'symbol' => $entryZone->symbol,
                'side' => $orderType,
                'type' => 'LIMIT',
                'price' => $price,
                'quantity' => $qtyPart,
                'time_in_force' => $makerConfig['mode'] ?? 'GTC',
                'maker_only' => $makerConfig['maker_only'] ?? true,
                'client_order_id' => $this->generateClientOrderId($entryZone->symbol, $side, 'maker_'.$idx)
            ];
        }

        return $orders;
    }

    /**
     * Génère les ordres fallback (IOC/MARKET)
     */
    private function generateFallbackOrders(
        EntryZoneDto $entryZone,
        array $sizingData,
        array $leverageData
    ): array {
        $config = $this->getOrderPlanConfig();
        $fallbackConfig = $config['fallback_taker'] ?? [];
        
        if (!($fallbackConfig['enable'] ?? true)) {
            return [];
        }

        $orders = [];
        $quantity = $sizingData['quantity'];
        $side = $entryZone->side;
        $maxSlipBps = $fallbackConfig['max_slip_bps'] ?? 5;
        
        // Ordre fallback avec contrôle de slippage
        $entryPrice = $entryZone->getMidPrice();
        $slippagePrice = $this->calculateSlippagePrice($entryPrice, $side, $maxSlipBps);
        $orderType = $side === 'LONG' ? 'buy_open_long' : 'sell_open_short';
        
        $orders[] = [
            'symbol' => $entryZone->symbol,
            'side' => $orderType,
            'type' => $fallbackConfig['type'] ?? 'IOC',
            'price' => $slippagePrice,
            'quantity' => $quantity,
            'time_in_force' => 'IOC',
            'client_order_id' => $this->generateClientOrderId($entryZone->symbol, $side, 'fallback')
        ];

        return $orders;
    }

    /**
     * Génère les ordres TP/SL
     */
    private function generateTpSlOrders(
        EntryZoneDto $entryZone,
        array $sizingData,
        array $leverageData
    ): array {
        $config = $this->getOrderPlanConfig();
        $tpSlConfig = $config['tp_sl'] ?? [];
        
        if (!($tpSlConfig['use_position_tp_sl'] ?? true)) {
            return [];
        }

        $orders = [];
        $side = $entryZone->side;
        $entryPrice = $entryZone->getMidPrice();
        $atrValue = $entryZone->atrValue;
        
        // Stop Loss
        $slPrice = $this->calculateStopLossPrice($entryPrice, $side, $atrValue, $sizingData['sl_mult']);
        $orders[] = [
            'symbol' => $entryZone->symbol,
            'side' => $side,
            'type' => 'STOP_LOSS',
            'price' => $slPrice,
            'plan_category' => 2, // Position TP/SL
            'price_type' => $tpSlConfig['price_type'] ?? 'last_price'
        ];
        
        // Take Profit
        $tpPrice = $this->calculateTakeProfitPrice($entryPrice, $side, $atrValue, $sizingData['sl_mult']);
        $orders[] = [
            'symbol' => $entryZone->symbol,
            'side' => $side,
            'type' => 'TAKE_PROFIT',
            'price' => $tpPrice,
            'plan_category' => 2, // Position TP/SL
            'price_type' => $tpSlConfig['price_type'] ?? 'last_price'
        ];

        return $orders;
    }

    /**
     * Génère les IDs et clés d'idempotence
     */
    private function generateIds(
        EntryZoneDto $entryZone,
        string $executionTimeframe,
        array $mtfContext
    ): array {
        $candleCloseTs = $mtfContext['candle_close_ts'] ?? time();
        $attempt = $mtfContext['attempt'] ?? 1;
        
        $clientOrderId = hash('sha256', implode(':', [
            $entryZone->symbol,
            $entryZone->side,
            $candleCloseTs,
            $executionTimeframe,
            $attempt
        ]));
        
        $decisionKey = sprintf(
            '%s:%s:%d',
            $entryZone->symbol,
            $executionTimeframe,
            $candleCloseTs
        );

        return [
            'client_order_id' => substr($clientOrderId, 0, 32),
            'decision_key' => $decisionKey
        ];
    }

    /**
     * Calcule les métriques de risque
     */
    private function calculateRiskMetrics(
        array $sizingData,
        array $leverageData,
        EntryZoneDto $entryZone
    ): array {
        $entryPrice = $entryZone->getMidPrice();
        $quantity = $sizingData['quantity'];
        $leverage = $leverageData['final_leverage'];
        $distanceStop = $sizingData['distance_stop'];
        
        $notional = $quantity * $entryPrice * $leverage;
        $riskAmount = $quantity * $distanceStop;
        
        $slPrice = $this->calculateStopLossPrice($entryPrice, $entryZone->side, $entryZone->atrValue, $sizingData['sl_mult']);
        $tpPrice = $this->calculateTakeProfitPrice($entryPrice, $entryZone->side, $entryZone->atrValue, $sizingData['sl_mult']);

        return [
            'notional' => $notional,
            'risk_amount' => $riskAmount,
            'risk_pct' => $sizingData['risk_pct'],
            'stop_loss_price' => $slPrice,
            'take_profit_price' => $tpPrice,
            'distance_stop' => $distanceStop,
            'leverage' => $leverage
        ];
    }

    /**
     * Construit les preuves
     */
    private function buildEvidence(
        array $sizingData,
        array $leverageData,
        MarketDataDto $marketData,
        array $mtfContext
    ): array {
        return [
            'sizing' => $sizingData,
            'leverage' => $leverageData,
            'market_data' => $marketData->toArray(),
            'mtf_context' => $mtfContext,
            'timestamp' => time()
        ];
    }

    // Méthodes utilitaires

    private function quantizeQuantity(float $quantity, array $contractDetails): float
    {
        $lotSize = $contractDetails['lot_size'] ?? 0.001;
        return floor($quantity / $lotSize) * $lotSize;
    }

    private function getTimeframeMultiplier(array $config, array $mtfContext): float
    {
        $timeframe = $mtfContext['execution_timeframe'] ?? '5m';
        $multipliers = $config['timeframe_multipliers'] ?? [];
        return $multipliers[$timeframe] ?? 1.0;
    }

    private function getConvictionMultiplier(array $mtfContext, array $config): float
    {
        $conviction = $mtfContext['conviction_flag'] ?? false;
        if (!$conviction) {
            return 1.0;
        }
        
        $convictionConfig = $config['conviction'] ?? [];
        return $convictionConfig['cap_pct_of_exchange'] ?? 1.0;
    }

    private function getMaxLeverageFromBracket(array $leverageBracket): float
    {
        if (empty($leverageBracket)) {
            return 20.0; // Default
        }
        
        $maxLeverage = 0;
        foreach ($leverageBracket as $bracket) {
            $maxLeverage = max($maxLeverage, (float) $bracket['max_leverage']);
        }
        
        return $maxLeverage;
    }

    private function getSymbolCap(string $symbol, array $config): float
    {
        $caps = $config['per_symbol_caps'] ?? [];
        foreach ($caps as $cap) {
            if (preg_match($cap['symbol_regex'], $symbol)) {
                return (float) $cap['cap'];
            }
        }
        return $config['exchange_cap'] ?? 20.0;
    }

    private function calculateSlippagePrice(float $price, string $side, float $maxSlipBps): float
    {
        $slippage = $maxSlipBps / 10000;
        return $side === 'LONG' ? $price * (1 + $slippage) : $price * (1 - $slippage);
    }

    private function calculateStopLossPrice(float $entryPrice, string $side, float $atrValue, float $slMult): float
    {
        $distance = $slMult * $atrValue;
        return $side === 'LONG' ? $entryPrice - $distance : $entryPrice + $distance;
    }

    private function calculateTakeProfitPrice(float $entryPrice, string $side, float $atrValue, float $slMult): float
    {
        $distance = $slMult * $atrValue * 2.0; // 2R par défaut
        return $side === 'LONG' ? $entryPrice + $distance : $entryPrice - $distance;
    }

    private function generateClientOrderId(string $symbol, string $side, string $type): string
    {
        return sprintf('%s_%s_%s_%d', $symbol, $side, $type, time());
    }

    // Configuration getters

    private function getSizingConfig(): array
    {
        $config = $this->config->getTradingConf('post_validation');
        return $config['sizing'] ?? [];
    }

    private function getLeverageConfig(): array
    {
        return $this->config->getTradingConf('leverage');
    }

    private function getOrderPlanConfig(): array
    {
        $config = $this->config->getTradingConf('post_validation');
        return $config['order_plan'] ?? [];
    }
}
