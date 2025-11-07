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
        ?float $stopPct = null
    ): int {
        $effectiveBudget = min(max($budgetUsdt, 0.0), max($availableUsdt, 0.0));
        if ($effectiveBudget <= 0.0) {
            throw new \RuntimeException('Budget indisponible pour calculer le levier');
        }

        $notional = $entryPrice * $contractSize * $positionSize;
        if ($notional <= 0.0) {
            return max(1, $minLeverage);
        }

        if ($stopPct === null || $stopPct <= 0.0 || !\is_finite($stopPct)) {
            $this->flowLogger->error('order_plan.leverage.missing_stop_pct', [
                'symbol' => $symbol,
                'stop_pct' => $stopPct,
                'reason' => 'stop_pct_invalid_or_missing',
            ]);
            throw new \RuntimeException(
                sprintf('stopPct requis pour calcul dynamique du levier (symbol: %s, stopPct: %s)', $symbol, $stopPct ?? 'null')
            );
        }

        // === 1) Defaults (risk_pct, k_dynamic) ===
        $defaults       = $this->tradeEntryConfig->getDefaults();
        $kDynamic       = (float)($defaults['k_dynamic'] ?? 10.0);
        $riskPctPercent = (float)($defaults['risk_pct_percent'] ?? 5.0);
        $riskPct        = $riskPctPercent > 1.0 ? $riskPctPercent / 100.0 : $riskPctPercent;

        $riskUsdt = $effectiveBudget * $riskPct;
        if ($riskUsdt <= 0.0) {
            throw new \RuntimeException('Risk USDT nul, impossible de calculer le levier');
        }

        // === 2) Base: risk_pct / stop_pct ===
        // leverage_base ≈ riskPct / stopPct (indépendant du budget)
        $leverageBase = $riskUsdt / max(1e-9, ($stopPct * max(1e-9, $effectiveBudget)));

        // === 3) Config levier ===
        $levConfig          = $this->tradeEntryConfig->getLeverage();
        $floorCfg           = (float)($levConfig['floor'] ?? 1.0);
        $exchangeCap        = (float)($levConfig['exchange_cap'] ?? $maxLeverage);
        $perSymbolCaps      = $levConfig['per_symbol_caps'] ?? [];
        $tfMultipliers      = $levConfig['timeframe_multipliers'] ?? [];
        $tfMult1m           = (float)($tfMultipliers['1m'] ?? 1.0); // tu exécutes en 1m

        // Cap par symbole (regex)
        $perSymbolCap = $this->resolvePerSymbolCap($symbol, $perSymbolCaps, $exchangeCap);

        // Cap dynamique k_dynamic/stop_pct
        $dynCap = $kDynamic / max(1e-9, $stopPct);

        // Cap global
        $globalCap = min(
            (float)$maxLeverage,   // fourni par l'exchange / appelant
            $exchangeCap,          // config globale
            $perSymbolCap,         // cap par symbole (BTC/ETH vs le reste)
            $dynCap                // cap dynamique lié à stop_pct
        );

        // === 4) Application multiplicateur TF (1m) ===
        $leverageFinal = $leverageBase * $tfMult1m;

        // === 5) Floor & clamp ===
        $leverageFinal = max($floorCfg, (float)$minLeverage, $leverageFinal);
        $leverageFinal = min($globalCap, $leverageFinal);

        // Arrondi & clamps finaux
        $leverage = (int)\ceil($leverageFinal);
        $leverage = max(1, max($minLeverage, $leverage));
        $leverage = min($maxLeverage, $leverage);

        $this->flowLogger->debug('order_plan.leverage.dynamic', [
            'symbol'         => $symbol,
            'entry_price'    => $entryPrice,
            'position_size'  => $positionSize,
            'notional'       => $notional,
            'budget_usdt'    => $effectiveBudget,
            'risk_pct'       => $riskPct,
            'risk_usdt'      => $riskUsdt,
            'stop_pct'       => $stopPct,
            'k_dynamic'      => $kDynamic,
            'leverage_base'  => $leverageBase,
            'tf_mult_1m'     => $tfMult1m,
            'dyn_cap'        => $dynCap,
            'exchange_cap'   => $exchangeCap,
            'per_symbol_cap' => $perSymbolCap,
            'global_cap'     => $globalCap,
            'leverage_final' => $leverageFinal,
            'leverage'       => $leverage,
            'min_leverage'   => $minLeverage,
            'max_leverage'   => $maxLeverage,
        ]);

        return $leverage;
    }

    /**
     * Cap par symbole via regex (^(BTC|ETH).* => cap différent, etc.)
     */
    private function resolvePerSymbolCap(string $symbol, array $perSymbolCaps, float $defaultCap): float
    {
        $cap = $defaultCap;
        foreach ($perSymbolCaps as $rule) {
            $regex = $rule['symbol_regex'] ?? null;
            $ruleCap = $rule['cap'] ?? null;
            if (!$regex || $ruleCap === null) {
                continue;
            }
            if (\preg_match('#' . $regex . '#', $symbol) === 1) {
                $cap = (float)$ruleCap;
                break;
            }
        }
        return $cap;
    }
}

