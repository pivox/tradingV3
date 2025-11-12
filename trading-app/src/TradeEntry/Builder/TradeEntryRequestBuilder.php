<?php
declare(strict_types=1);

namespace App\TradeEntry\Builder;

use App\Config\{TradeEntryConfig, TradeEntryConfigProvider};
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builder pour créer un TradeEntryRequest depuis des champs MTF minimaux.
 * Délègue la logique de construction depuis TradingDecisionHandler.
 * Utilise TradeEntryConfigProvider avec mode (même mécanisme que validations.{mode}.yaml).
 */
final class TradeEntryRequestBuilder
{
    public function __construct(
        private readonly TradeEntryConfigProvider $configProvider,
        private readonly TradeEntryConfig $defaultConfig, // Fallback si mode non fourni
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    /**
     * Construit un TradeEntryRequest depuis des champs minimaux (MTF → TradeEntry).
     * On réduit la dépendance à SymbolResultDto pour ne garder que le nécessaire.
     *
     * @param string      $symbol       Symbole
     * @param string      $signalSide   'LONG' ou 'SHORT'
     * @param string|null $executionTf  TF d'exécution effectif (ex: '1m','5m','15m')
     * @param float|null  $price        Prix courant (optionnel)
     * @param float|null  $atr          Valeur ATR du TF d'exécution (optionnel si stop_from ≠ 'atr')
     * @param string|null $mode         Mode de configuration (ex: 'regular', 'scalping'). Si null, utilise la config par défaut.
     * @return TradeEntryRequest|null
     */
    public function fromMtfSignal(
        string $symbol,
        string $signalSide,
        ?string $executionTf = null,
        ?float $price = null,
        ?float $atr = null,
        ?string $mode = null
    ): ?TradeEntryRequest {
        $side = strtoupper((string)$signalSide);
        if (!in_array($side, ['LONG', 'SHORT'], true)) {
            return null;
        }

        $price = $price ?? null;
        $atr = $atr ?? null;

        // Charger la config selon le mode (même mécanisme que validations.{mode}.yaml)
        $config = $this->getConfigForMode($mode);
        $executionTf = strtolower($executionTf ?? '1m');
        $defaults = $config->getDefaults();
        $multipliers = $defaults['timeframe_multipliers'] ?? [];
        $tfMultiplier = (float)($multipliers[$executionTf] ?? 1.0);

        $riskPctPercent = (float)($defaults['risk_pct_percent'] ?? 2.0);
        $riskPct = max(0.0, $riskPctPercent / 100.0) * $tfMultiplier;
        if ($riskPct <= 0.0) {
            return null;
        }

        $initialMargin = max(0.0, (float)($defaults['initial_margin_usdt'] ?? 100.0) * $tfMultiplier);
        if ($initialMargin <= 0.0) {
            $fallbackCapital = (float)($defaults['fallback_account_balance'] ?? 0.0);
            $initialMargin = $fallbackCapital * $riskPct;
        }

        if ($initialMargin <= 0.0) {
            return null;
        }

        $stopFrom = $defaults['stop_from'] ?? 'risk';
        $atrK = (float)($defaults['atr_k'] ?? 1.5);
        $atrValue = ($atr !== null && $atr > 0.0) ? $atr : null;
        
        // GARDE CRITIQUE : Si stop_from='atr' est configuré mais ATR invalide/manquant, REJETER l'ordre
        if ($stopFrom === 'atr' && ($atrValue === null || $atrValue <= 0.0)) {
            $this->positionsLogger->warning('[TradeEntryRequestBuilder] ATR required but invalid/missing', [
                'symbol' => $symbol,
                'stop_from' => $stopFrom,
                'atr' => $atr,
                'atr_value' => $atrValue,
            ]);
            $this->positionsLogger->info('order_journey.preconditions.blocked', [
                'symbol' => $symbol,
                'reason' => 'atr_required_but_invalid',
                'stop_from' => $stopFrom,
                'atr' => $atr,
            ]);
            return null;  // BLOQUER l'ordre au lieu de basculer silencieusement sur 'risk'
        }

        $orderType = $defaults['order_type'] ?? 'limit';
        // entryLimitHint est optionnel; si null, OrderPlanBuilder utilisera best bid/ask
        $entryLimitHint = ($orderType === 'limit' && $price !== null) ? $price : null;

        $marketMaxSpreadPct = (float)($defaults['market_max_spread_pct'] ?? 0.001);
        if ($marketMaxSpreadPct > 1.0) {
            $marketMaxSpreadPct /= 100.0;
        }

        $insideTicks = (int)($defaults['inside_ticks'] ?? 1);
        $maxDeviationPct = isset($defaults['max_deviation_pct']) ? (float)$defaults['max_deviation_pct'] : null;
        $implausiblePct = isset($defaults['implausible_pct']) ? (float)$defaults['implausible_pct'] : null;
        $zoneMaxDeviationPct = isset($defaults['zone_max_deviation_pct']) ? (float)$defaults['zone_max_deviation_pct'] : null;

        $tpPolicy = (string)($defaults['tp_policy'] ?? 'pivot_conservative');
        $tpBufferPct = isset($defaults['tp_buffer_pct']) ? (float)$defaults['tp_buffer_pct'] : null;
        if ($tpBufferPct !== null && $tpBufferPct <= 0.0) {
            $tpBufferPct = null;
        }
        $tpBufferTicks = isset($defaults['tp_buffer_ticks']) ? (int)$defaults['tp_buffer_ticks'] : null;
        if ($tpBufferTicks !== null && $tpBufferTicks <= 0) {
            $tpBufferTicks = null;
        }
        $tpMinKeepRatio = (float)($defaults['tp_min_keep_ratio'] ?? 0.95);
        $tpMaxExtraR = isset($defaults['tp_max_extra_r']) ? (float)$defaults['tp_max_extra_r'] : null;
        if ($tpMaxExtraR !== null && $tpMaxExtraR < 0.0) {
            $tpMaxExtraR = null;
        }

        $pivotSlPolicy = (string)($defaults['pivot_sl_policy'] ?? 'nearest_below');
        $pivotSlBufferPct = isset($defaults['pivot_sl_buffer_pct']) ? (float)$defaults['pivot_sl_buffer_pct'] : null;
        if ($pivotSlBufferPct !== null && $pivotSlBufferPct < 0.0) {
            $pivotSlBufferPct = null;
        }
        $pivotSlMinKeepRatio = isset($defaults['pivot_sl_min_keep_ratio']) ? (float)$defaults['pivot_sl_min_keep_ratio'] : null;
        if ($pivotSlMinKeepRatio !== null && $pivotSlMinKeepRatio <= 0.0) {
            $pivotSlMinKeepRatio = null;
        }

        $sideEnum = $side === 'LONG' ? Side::Long : Side::Short;

        return new TradeEntryRequest(
            symbol: $symbol,
            side: $sideEnum,
            executionTf: $executionTf,
            orderType: $orderType,
            openType: $defaults['open_type'] ?? 'isolated',
            orderMode: (int)($defaults['order_mode'] ?? 1),
            initialMarginUsdt: $initialMargin,
            riskPct: $riskPct,
            rMultiple: (float)($defaults['r_multiple'] ?? 2.0),
            entryLimitHint: $entryLimitHint,
            stopFrom: $stopFrom,
            pivotSlPolicy: $pivotSlPolicy,
            pivotSlBufferPct: $pivotSlBufferPct,
            pivotSlMinKeepRatio: $pivotSlMinKeepRatio,
            atrValue: $atrValue,
            atrK: (float)$atrK,
            marketMaxSpreadPct: $marketMaxSpreadPct,
            insideTicks: $insideTicks,
            maxDeviationPct: $maxDeviationPct,
            implausiblePct: $implausiblePct,
            zoneMaxDeviationPct: $zoneMaxDeviationPct,
            tpPolicy: $tpPolicy,
            tpBufferPct: $tpBufferPct,
            tpBufferTicks: $tpBufferTicks,
            tpMinKeepRatio: $tpMinKeepRatio,
            tpMaxExtraR: $tpMaxExtraR,
        );
    }

    /**
     * Charge la config selon le mode (même mécanisme que validations.{mode}.yaml)
     * @param string|null $mode Mode de configuration (ex: 'regular', 'scalping')
     * @return TradeEntryConfig
     */
    private function getConfigForMode(?string $mode): TradeEntryConfig
    {
        if ($mode === null || $mode === '') {
            return $this->defaultConfig;
        }

        try {
            return $this->configProvider->getConfigForMode($mode);
        } catch (\RuntimeException $e) {
            // Si le mode n'existe pas, utiliser la config par défaut
            $this->positionsLogger->warning('trade_entry_request_builder.mode_not_found', [
                'mode' => $mode,
                'error' => $e->getMessage(),
                'fallback' => 'default_config',
            ]);
            return $this->defaultConfig;
        }
    }
}
