<?php

namespace App\Indicator\Context;

use App\Indicator\Condition\ConditionRegistry;

/**
 * Test simple pour vérifier que toutes les conditions fonctionnent
 * avec le nouveau IndicatorContextBuilder.
 */
class IndicatorContextBuilderTest
{
    public function __construct(
        private readonly IndicatorContextBuilder $contextBuilder,
        private readonly ConditionRegistry $conditionRegistry
    ) {}

    /**
     * Teste toutes les conditions avec un contexte complet.
     */
    public function testAllConditions(): array
    {
        // Créer un contexte avec toutes les données nécessaires
        $context = $this->contextBuilder
            ->symbol('BTCUSDT')
            ->timeframe('1h')
            ->closes([50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900, 51000, 51100, 51200, 51300, 51400])
            ->highs([50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900, 51000, 51100, 51200, 51300, 51400, 51500])
            ->lows([49900, 50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900, 51000, 51100, 51200, 51300])
            ->volumes([1000, 1100, 1200, 1300, 1400, 1500, 1600, 1700, 1800, 1900, 2000, 2100, 2200, 2300, 2400])
            ->entryPrice(51200.0)          // Prix d'entrée
            ->stopLoss(51000.0)            // Stop loss
            ->withDefaults()               // Paramètres par défaut
            ->build();

        // Évaluer toutes les conditions
        $results = $this->conditionRegistry->evaluate($context);

        return [
            'context' => $context,
            'conditions_results' => $results,
            'summary' => $this->generateSummary($results)
        ];
    }

    /**
     * Génère un résumé des résultats des conditions.
     */
    private function generateSummary(array $results): array
    {
        $total = count($results);
        $passed = 0;
        $failed = 0;
        $errors = 0;

        foreach ($results as $name => $result) {
            if (isset($result['meta']['error']) && $result['meta']['error']) {
                $errors++;
            } elseif ($result['passed']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'total_conditions' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'errors' => $errors,
            'success_rate' => $total > 0 ? round(($passed / $total) * 100, 2) : 0
        ];
    }

    /**
     * Teste une condition spécifique.
     */
    public function testSpecificCondition(string $conditionName): array
    {
        $context = $this->contextBuilder
            ->symbol('ETHUSDT')
            ->timeframe('4h')
            ->closes([3000, 3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080, 3090, 3100, 3110, 3120, 3130, 3140])
            ->highs([3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080, 3090, 3100, 3110, 3120, 3130, 3140, 3150])
            ->lows([2990, 3000, 3010, 3020, 3030, 3040, 3050, 3060, 3070, 3080, 3090, 3100, 3110, 3120, 3130])
            ->volumes([500, 550, 600, 650, 700, 750, 800, 850, 900, 950, 1000, 1050, 1100, 1150, 1200])
            ->withDefaults()
            ->build();

        $results = $this->conditionRegistry->evaluate($context, [$conditionName]);

        return [
            'condition_name' => $conditionName,
            'context' => $context,
            'result' => $results[$conditionName] ?? null,
            'available_conditions' => $this->conditionRegistry->names()
        ];
    }
}


