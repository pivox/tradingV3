<?php
declare(strict_types=1);

namespace App\TradeEntry\Service\Leverage;

use App\Config\TradeEntryConfig;
use App\Contract\EntryTrade\LeverageServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DynamicLeverageService implements LeverageServiceInterface
{
    public function __construct(
        private readonly TradeEntryConfig $tradeEntryConfig,
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $flowLogger,
    ) {}

    public function computeLeverage(
        string $symbol,
        float $entryPrice,
        float $contractSize,
        int $positionSize,
        float $budgetUsdt,
        float $availableUsdt,
        int $minLeverage,
        int $maxLeverage,
        ?float $stopPct = null,
        ?float $atr5mValue = null // peut recevoir ATR 15m, le nom du param n’a pas d’incidence
    ): int {
        // Budget effectif borné
        $effectiveBudget = min(max($budgetUsdt, 0.0), max($availableUsdt, 0.0));
        if ($effectiveBudget <= 0.0) {
            throw new \RuntimeException('Budget indisponible pour calculer le levier');
        }

        // Notional
        $notional = $entryPrice * $contractSize * $positionSize;
        if ($notional <= 0.0) {
            return max(1, $minLeverage);
        }

        if ($stopPct === null || $stopPct <= 0.0 || !\is_finite($stopPct)) {
            $this->flowLogger->error('order_plan.leverage.missing_stop_pct', [
                'symbol' => $symbol, 'stop_pct' => $stopPct,
            ]);
            throw new \RuntimeException('stopPct requis pour calcul dynamique du levier');
        }

        // --- Lecture config ---
        $defaults  = $this->tradeEntryConfig->getDefaults();
        $levConfig = $this->tradeEntryConfig->getLeverage();

        $riskPctPercent = (float)($defaults['risk_pct_percent'] ?? 5.0);
        $riskPct = $riskPctPercent > 1.0 ? $riskPctPercent / 100.0 : $riskPctPercent;

        $kDynamic = (float)($defaults['k_dynamic'] ?? 10.0);

        $floorConfig    = (float)($levConfig['floor'] ?? 1.0);
        $exchangeCapCfg = (float)($levConfig['exchange_cap'] ?? $maxLeverage);
        $tfMultipliers  = (array)($levConfig['timeframe_multipliers'] ?? []);
        $perSymbolCaps  = (array)($levConfig['per_symbol_caps'] ?? []);
        $roundingCfg    = (array)($levConfig['rounding'] ?? []);

        // Exécution 1m → multiplicateur 1m ; sinon fallback 1.0
        $tfMult = (float)($tfMultipliers['1m'] ?? 1.0);

        // --- Base leverage : riskPct / stopPct
        $leverageBase = $riskPct / max($stopPct, 1e-9);

        // Cap dynamique lié à la distance de stop
        $dynCap = $kDynamic > 0.0
            ? min((float)$maxLeverage, $kDynamic / max($stopPct, 1e-9))
            : (float)$maxLeverage;

        // Modulateur de volatilité à partir de l’ATR/Price (réduit le levier si vol élevée)
        $volMult = $this->computeVolatilityMultiplier($atr5mValue, $entryPrice);

        // Application TF + Vol
        $leveragePreCaps = $leverageBase * $tfMult * $volMult;

        // Cap exchange global
        $leveragePreCaps = min($leveragePreCaps, $exchangeCapCfg);

        // Cap par symbole (regex)
        $symbolCap = $this->resolveSymbolCap($symbol, $perSymbolCaps, $exchangeCapCfg);
        $leveragePreCaps = min($leveragePreCaps, $symbolCap);

        // Cap dynamique
        $leveragePreCaps = min($leveragePreCaps, $dynCap);

        // Clamp min/max exchange
        $leverageFinal = max((float)$minLeverage, min((float)$maxLeverage, $leveragePreCaps));

        // Respect du floor
        $leverageFinal = max($floorConfig, $leverageFinal);

        // Arrondi
        $roundMode = strtolower((string)($roundingCfg['mode'] ?? 'ceil'));
        $leverageRounded = match ($roundMode) {
            'floor' => (int)floor($leverageFinal),
            'round' => (int)round($leverageFinal),
            default => (int)ceil($leverageFinal),
        };

        // Clamps finaux
        $leverageRounded = max(1, $leverageRounded);
        $leverageRounded = max($minLeverage, $leverageRounded);
        $leverageRounded = min($maxLeverage, $leverageRounded);

        $this->flowLogger->debug('order_plan.leverage.dynamic', [
            'symbol'            => $symbol,
            'entry_price'       => $entryPrice,
            'contract_size'     => $contractSize,
            'position_size'     => $positionSize,
            'notional'          => $notional,
            'budget_usdt'       => $budgetUsdt,
            'available_usdt'    => $availableUsdt,
            'effective_budget'  => $effectiveBudget,
            'risk_pct'          => $riskPct,
            'risk_pct_percent'  => $riskPctPercent,
            'stop_pct'          => $stopPct,
            'k_dynamic'         => $kDynamic,
            'leverage_base'     => $leverageBase,
            'tf_mult_1m'        => $tfMult,
            'atr_value'         => $atr5mValue,
            'vol_mult'          => $volMult,
            'exchange_cap_cfg'  => $exchangeCapCfg,
            'symbol_cap'        => $symbolCap,
            'dyn_cap'           => $dynCap,
            'floor_config'      => $floorConfig,
            'leverage_precaps'  => $leveragePreCaps,
            'leverage_final'    => $leverageFinal,
            'leverage_rounded'  => $leverageRounded,
            'min_leverage'      => $minLeverage,
            'max_leverage'      => $maxLeverage,
        ]);

        return $leverageRounded;
    }

    /**
     * Réduit progressivement le levier quand ATR/Price augmente.
     * Mappe ~[0%, 1%] -> multiplicateur ~[1.25, 0.5].
     */
    private function computeVolatilityMultiplier(?float $atr, float $price): float
    {
        if ($atr === null || $atr <= 0.0 || $price <= 0.0) {
            return 1.0;
        }
        $volPct = $atr / $price;           // ex: 0.002 = 0.2%
        $m      = 1.25 - 75.0 * $volPct;   // vol 0% -> 1.25 ; vol 1% -> 0.5
        return max(0.5, min(1.25, $m));
    }

    /**
     * Applique les caps par regex de symbole, sinon fallback à exchangeCap.
     * Format attendu: [ { symbol_regex: "...", cap: float }, ... ]
     */
    private function resolveSymbolCap(string $symbol, array $perSymbolCaps, float $fallback): float
    {
        $cap = $fallback;
        foreach ($perSymbolCaps as $rule) {
            $re  = (string)($rule['symbol_regex'] ?? '');
            $val = (float)($rule['cap'] ?? 0.0);
            if ($re !== '' && @preg_match('/' . $re . '/i', $symbol) === 1 && $val > 0.0) {
                $cap = min($cap, $val);
            }
        }
        return $cap;
    }
}
