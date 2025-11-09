<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Volume\Vwap;
use App\Indicator\Registry\ConditionRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

class AllConditionsTest extends TestCase
{
    private IndicatorContextBuilder $contextBuilder;
    private ConditionRegistry $conditionRegistry;

    protected function setUp(): void
    {
        // Créer les dépendances nécessaires
        $rsi = new Rsi();
        $macd = new Macd();
        $ema = new Ema();
        $adx = new Adx();
        $vwap = new Vwap();
        $atrCalc = new AtrCalculator();

        $this->contextBuilder = new IndicatorContextBuilder($rsi, $macd, $ema, $adx, $vwap, $atrCalc);

        $conditions = [];
        $conditionsByName = [];
        $projectRoot = dirname(dirname(dirname(__DIR__)));
        $conditionDir = $projectRoot . '/src/Indicator/Condition';
        foreach (glob($conditionDir . '/*Condition.php') ?: [] as $file) {
            $class = 'App\\Indicator\\Condition\\' . basename($file, '.php');
            if (!is_subclass_of($class, ConditionInterface::class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $condition = $reflection->newInstance();
            $conditions[] = $condition;
            // Créer un index par nom pour le locator
            $name = $condition->getName();
            $conditionsByName[$name] = $condition;
        }

        // Créer un mock du ContainerInterface qui implémente ServiceProviderInterface
        $locator = $this->createMock(ContainerInterface::class);
        if ($locator instanceof ServiceProviderInterface || method_exists($locator, 'getProvidedServices')) {
            $locator->method('getProvidedServices')->willReturn(array_fill_keys(array_keys($conditionsByName), 'App\\Indicator\\Condition\\ConditionInterface'));
        }
        $locator->method('has')->willReturnCallback(function ($name) use ($conditionsByName) {
            return isset($conditionsByName[$name]);
        });
        $locator->method('get')->willReturnCallback(function ($name) use ($conditionsByName) {
            return $conditionsByName[$name] ?? null;
        });

        $this->conditionRegistry = new ConditionRegistry($conditionsByName, $locator);
    }

    public function testAllConditionsWithValidData(): void
    {
        // Créer un contexte avec des données réalistes
        $context = $this->contextBuilder
            ->symbol('BTCUSDT')
            ->timeframe('1h')
            ->closes([50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900, 51000, 51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800, 51900, 52000])
            ->highs([50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900, 51000, 51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800, 51900, 52000, 52100])
            ->lows([49900, 50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900, 51000, 51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800, 51900])
            ->volumes([1000, 1100, 1200, 1300, 1400, 1500, 1600, 1700, 1800, 1900, 2000, 2100, 2200, 2300, 2400, 2500, 2600, 2700, 2800, 2900, 3000])
            ->entryPrice(51200.0)
            ->stopLoss(51000.0)
            ->withDefaults()
            ->build();

        // Évaluer toutes les conditions
        $results = $this->conditionRegistry->evaluate($context);

        // Vérifier que toutes les conditions ont été évaluées
        $this->assertNotEmpty($results, 'Aucune condition n\'a été évaluée');

        // Vérifier la structure des résultats
        foreach ($results as $conditionName => $result) {
            $this->assertIsArray($result, "Le résultat de la condition '$conditionName' doit être un tableau");
            $this->assertArrayHasKey('name', $result, "Le résultat de '$conditionName' doit avoir une clé 'name'");
            $this->assertArrayHasKey('passed', $result, "Le résultat de '$conditionName' doit avoir une clé 'passed'");
            $this->assertArrayHasKey('value', $result, "Le résultat de '$conditionName' doit avoir une clé 'value'");
            $this->assertArrayHasKey('threshold', $result, "Le résultat de '$conditionName' doit avoir une clé 'threshold'");
            $this->assertArrayHasKey('meta', $result, "Le résultat de '$conditionName' doit avoir une clé 'meta'");

            $this->assertIsBool($result['passed'], "Le champ 'passed' de '$conditionName' doit être un booléen");
            $this->assertIsArray($result['meta'], "Le champ 'meta' de '$conditionName' doit être un tableau");
        }

        // Afficher un résumé pour debug uniquement si demandé explicitement
        if (getenv('SHOW_CONDITION_SUMMARY')) {
            $this->displayResultsSummary($results);
        }
    }

    public function testAllConditionsWithInsufficientData(): void
    {
        // Créer un contexte avec des données insuffisantes
        $context = $this->contextBuilder
            ->symbol('ETHUSDT')
            ->timeframe('4h')
            ->closes([3000, 3010]) // Données insuffisantes pour calculer les indicateurs
            ->build();

        $results = $this->conditionRegistry->evaluate($context);

        $this->assertNotEmpty($results, 'Les conditions devraient être évaluées même avec données insuffisantes');

        // Vérifier que les conditions gèrent correctement les données manquantes
        foreach ($results as $conditionName => $result) {
            $this->assertIsArray($result, "Le résultat de la condition '$conditionName' doit être un tableau");
            $this->assertArrayHasKey('passed', $result, "Le résultat de '$conditionName' doit avoir une clé 'passed'");

            // La plupart des conditions devraient échouer avec des données insuffisantes
            if (isset($result['meta']['missing_data']) && $result['meta']['missing_data']) {
                $this->assertFalse($result['passed'], "La condition '$conditionName' devrait échouer avec des données manquantes");
            }
        }
    }

    public function testSpecificConditionsWithEdgeCases(): void
    {
        // Test avec des valeurs limites
        $context = $this->contextBuilder
            ->symbol('ADAUSDT')
            ->timeframe('1d')
            ->closes([1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0]) // Prix constant
            ->highs([1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0])
            ->lows([1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0])
            ->volumes([100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100, 100])
            ->withDefaults()
            ->build();

        $results = $this->conditionRegistry->evaluate($context);

        $this->assertNotEmpty($results, 'Les conditions devraient être évaluées sur des données constantes');

        // Vérifier que les conditions gèrent les valeurs constantes
        foreach ($results as $conditionName => $result) {
            $this->assertIsArray($result, "Le résultat de la condition '$conditionName' doit être un tableau");
            $this->assertArrayHasKey('passed', $result, "Le résultat de '$conditionName' doit avoir une clé 'passed'");
        }
    }

    private function displayResultsSummary(array $results): void
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

        echo "\n=== RÉSUMÉ DES CONDITIONS ===\n";
        echo "Total: $total\n";
        echo "Passées: $passed\n";
        echo "Échouées: $failed\n";
        echo "Erreurs: $errors\n";
        echo "Taux de réussite: " . ($total > 0 ? round(($passed / $total) * 100, 2) : 0) . "%\n";
        echo "=============================\n";
    }
}
