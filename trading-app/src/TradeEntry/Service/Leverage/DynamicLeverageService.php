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

        // Si stopPct n'est pas fourni ou invalide, on ne peut pas calculer dynamiquement
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

        // Lire kDynamic depuis la config
        $defaults = $this->tradeEntryConfig->getDefaults();
        $kDynamic = (float)($defaults['k_dynamic'] ?? 10.0);
        $riskPctPercent = (float)($defaults['risk_pct_percent'] ?? 5.0);
        $riskPct = $riskPctPercent > 1.0 ? $riskPctPercent / 100.0 : $riskPctPercent;

        // Calcul du risk USDT
        $riskUsdt = $effectiveBudget * $riskPct;
        if ($riskUsdt <= 0.0) {
            throw new \RuntimeException('Risk USDT nul, impossible de calculer le levier');
        }

        // Formule dynamique : leverage = risk_usdt / (stop_pct * budget_usdt)
        $leverageBase = $riskUsdt / max(1e-9, ($stopPct * max(1e-9, $effectiveBudget)));

        // Cap dynamique : min(levMax, kDynamic / stop_pct)
        $dynCap = min((float)$maxLeverage, $kDynamic / max(1e-9, $stopPct));

        // Levier final : max(levMin, min(leverageBase, dynCap))
        $leverageFinal = max((float)$minLeverage, min($leverageBase, $dynCap));

        // Arrondir à l'entier supérieur (comme l'ancien comportement)
        $leverage = (int)ceil($leverageFinal);

        // Clamp final
        $leverage = max(1, max($minLeverage, $leverage));
        $leverage = min($maxLeverage, $leverage);

        $this->flowLogger->debug('order_plan.leverage.dynamic', [
            'symbol' => $symbol,
            'entry_price' => $entryPrice,
            'position_size' => $positionSize,
            'notional' => $notional,
            'budget_usdt' => $effectiveBudget,
            'risk_pct' => $riskPct,
            'risk_usdt' => $riskUsdt,
            'stop_pct' => $stopPct,
            'k_dynamic' => $kDynamic,
            'leverage_base' => $leverageBase,
            'dyn_cap' => $dynCap,
            'leverage_final' => $leverageFinal,
            'leverage' => $leverage,
            'min_leverage' => $minLeverage,
            'max_leverage' => $maxLeverage,
        ]);

        return $leverage;
    }
}

