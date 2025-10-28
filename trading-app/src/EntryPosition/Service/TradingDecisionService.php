<?php

declare(strict_types=1);

namespace App\EntryPosition\Service;

use App\Common\Enum\SignalSide;
use App\Contract\EntryTrade\TradingDecisionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service de décision de trading vide avec logs
 * Implémentation temporaire pour permettre au MtfRunService de fonctionner
 */
#[AsAlias(id: TradingDecisionInterface::class)]
class TradingDecisionService implements TradingDecisionInterface
{
    public function __construct(
        #[Autowire('@logger')]
        private readonly LoggerInterface $logger
    ) {
    }

    public function makeTradingDecision(
        string $symbol,
        SignalSide $side,
        float $currentPrice,
        float $atr,
        float $accountBalance,
        float $riskPercentage,
        bool $isHighConviction = false,
        float $timeframeMultiplier = 1.0
    ): array {
        $this->logger->info('[TradingDecision] Making trading decision', [
            'symbol' => $symbol,
            'side' => $side->value,
            'current_price' => $currentPrice,
            'atr' => $atr,
            'account_balance' => $accountBalance,
            'risk_percentage' => $riskPercentage,
            'is_high_conviction' => $isHighConviction,
            'timeframe_multiplier' => $timeframeMultiplier,
        ]);

        // Calculer la taille de position basique (exemple)
        $riskAmount = $accountBalance * ($riskPercentage / 100);
        $positionSize = $riskAmount / $atr;

        // Appliquer le multiplicateur de timeframe
        $adjustedPositionSize = $positionSize * $timeframeMultiplier;

        // Ajuster selon la conviction
        if ($isHighConviction) {
            $adjustedPositionSize *= 1.2; // +20% pour haute conviction
        }

        $decision = [
            'status' => 'calculated',
            'action' => 'no_action', // Pas d'action réelle pour l'instant
            'symbol' => $symbol,
            'side' => $side->value,
            'current_price' => $currentPrice,
            'atr' => $atr,
            'account_balance' => $accountBalance,
            'risk_percentage' => $riskPercentage,
            'risk_amount' => $riskAmount,
            'position_size' => $adjustedPositionSize,
            'is_high_conviction' => $isHighConviction,
            'timeframe_multiplier' => $timeframeMultiplier,
            'calculated_at' => date('Y-m-d H:i:s'),
        ];

        $this->logger->info('[TradingDecision] Decision calculated', [
            'symbol' => $symbol,
            'decision' => $decision,
        ]);

        return $decision;
    }
}
