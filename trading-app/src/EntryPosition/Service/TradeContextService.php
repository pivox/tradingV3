<?php

declare(strict_types=1);

namespace App\EntryPosition\Service;

use App\Contract\EntryTrade\TradeContextInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service de contexte de trading vide avec logs
 * Implémentation temporaire pour permettre au MtfRunService de fonctionner
 */
#[AsAlias(id: TradeContextInterface::class)]
class TradeContextService implements TradeContextInterface
{
    public function __construct(
        #[Autowire('@logger')]
        private readonly LoggerInterface $logger
    ) {
    }

    public function getAccountBalance(): float
    {
        $balance = 10000.0; // Valeur par défaut pour les tests
        $this->logger->info('[TradeContext] Getting account balance', [
            'balance' => $balance,
        ]);
        return $balance;
    }

    public function getRiskPercentage(): float
    {
        $riskPercentage = 2.0; // 2% par défaut
        $this->logger->info('[TradeContext] Getting risk percentage', [
            'risk_percentage' => $riskPercentage,
        ]);
        return $riskPercentage;
    }

    public function getTimeframeMultiplier(string $tf): float
    {
        $multipliers = [
            '1m' => 1.0,
            '5m' => 1.2,
            '15m' => 1.5,
            '1h' => 2.0,
            '4h' => 3.0,
        ];

        $multiplier = $multipliers[$tf] ?? 1.0;

        $this->logger->info('[TradeContext] Getting timeframe multiplier', [
            'timeframe' => $tf,
            'multiplier' => $multiplier,
        ]);

        return $multiplier;
    }
}
