<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

use App\Config\MtfValidationConfig;
use App\Contract\Indicator\IndicatorEngineInterface;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use App\MtfValidator\Execution\ExecutionSelector;

/**
 * Service principal de validation fonctionnelle
 * Teste les règles avec des données simulées et vérifie la cohérence logique
 */
final class FunctionalValidationRunner
{
    public function __construct(
        private readonly MtfValidationConfig $config,
        private readonly ConditionRegistry $conditionRegistry,
        private readonly IndicatorEngineInterface $indicatorEngine,
        private readonly ExecutionSelector $executionSelector,
        private readonly TestContextBuilder $contextBuilder,
        private readonly LogicalConsistencyChecker $consistencyChecker
    ) {
    }

    public function run(): FunctionalValidationResult
    {
        $result = new FunctionalValidationResult();
        
        // 1. Vérifier la cohérence logique
        $consistencyIssues = $this->consistencyChecker->check();
        foreach ($consistencyIssues as $issue) {
            $result->addConsistencyIssue($issue);
        }
        
        // 2. Charger les règles dans le registry
        $this->conditionRegistry->load($this->config);
        
        // 3. Tester les règles individuelles
        $this->testRules($result);
        
        // 4. Tester les scénarios
        $this->testScenarios($result);
        
        // 5. Tester l'execution selector
        $this->testExecutionSelector($result);
        
        return $result;
    }

    private function testRules(FunctionalValidationResult $result): void
    {
        $rules = $this->config->getRules();
        $testContexts = [
            'bullish' => $this->contextBuilder->buildBullishContext(),
            'bearish' => $this->contextBuilder->buildBearishContext(),
            'sideways' => $this->contextBuilder->buildSidewaysContext(),
        ];
        
        foreach ($rules as $ruleName => $ruleSpec) {
            // Tester la règle avec différents contextes
            foreach ($testContexts as $contextType => $context) {
                try {
                    // Vérifier si c'est une règle ou une condition PHP
                    $rulesCard = $this->conditionRegistry->getRules();
                    $rule = $rulesCard?->get($ruleName);
                    
                    if ($rule) {
                        $ruleResult = $rule->evaluate($context);
                        $passed = $ruleResult->passed;
                    } else {
                        // C'est peut-être une condition PHP
                        $condition = $this->conditionRegistry->get($ruleName);
                        if (!$condition) {
                            continue; // Ni règle ni condition
                        }
                        $ruleResult = $condition->evaluate($context);
                        $passed = $ruleResult->passed;
                    }
                    
                    $result->addRuleResult(new RuleTestResult(
                        $ruleName,
                        $passed,
                        $ruleResult->value,
                        null, // Pas d'attente spécifique pour les tests génériques
                        "Contexte {$contextType}",
                        ['context_type' => $contextType, 'meta' => $ruleResult->meta ?? []]
                    ));
                } catch (\Throwable $e) {
                    $result->addRuleResult(new RuleTestResult(
                        $ruleName,
                        false,
                        null,
                        null,
                        "Contexte {$contextType}",
                        ['error' => $e->getMessage(), 'context_type' => $contextType]
                    ));
                }
            }
        }
    }

    private function testScenarios(FunctionalValidationResult $result): void
    {
        $scenarios = $this->buildTestScenarios();
        $validation = $this->config->getValidation();
        
        foreach ($scenarios as $scenario) {
            $timeframe = $scenario->getTimeframe();
            $side = $scenario->getSide();
            $context = $scenario->getContext();
            
            try {
                // Évaluer la validation pour ce timeframe et side
                $evalResult = $this->indicatorEngine->evaluateYaml($timeframe, $context);
                
                $passed = false;
                $executionTf = null;
                
                if (isset($evalResult['passed'][$side])) {
                    $passed = (bool)$evalResult['passed'][$side];
                }
                
                // Tester l'execution selector si applicable
                if ($passed && $timeframe === '15m') {
                    $selectorContext = $this->contextBuilder->enrichForExecutionSelector($context);
                    $execDecision = $this->executionSelector->decide($selectorContext);
                    $executionTf = $execDecision->executionTimeframe;
                }
                
                $scenarioResult = new ScenarioTestResult(
                    $scenario->getName(),
                    $passed,
                    $timeframe,
                    $side,
                    $executionTf,
                    $scenario->getExpectedExecutionTf(),
                    $passed ? 'Scénario passé' : 'Scénario échoué'
                );
                
                // Ajouter les résultats des règles individuelles
                if (isset($evalResult[$side])) {
                    foreach ($evalResult[$side]['conditions'] ?? [] as $ruleName => $ruleData) {
                        $scenarioResult->addRuleResult(new RuleTestResult(
                            $ruleName,
                            (bool)($ruleData['passed'] ?? false),
                            $ruleData['value'] ?? null,
                            null,
                            "Scénario {$scenario->getName()}",
                            $ruleData['meta'] ?? []
                        ));
                    }
                }
                
                $result->addScenarioResult($scenarioResult);
            } catch (\Throwable $e) {
                $result->addScenarioResult(new ScenarioTestResult(
                    $scenario->getName(),
                    false,
                    $timeframe,
                    $side,
                    null,
                    $scenario->getExpectedExecutionTf(),
                    "Erreur: {$e->getMessage()}"
                ));
            }
        }
    }

    private function testExecutionSelector(FunctionalValidationResult $result): void
    {
        $testCases = [
            [
                'name' => 'Stay on 15m - High R multiple',
                'context' => $this->contextBuilder->enrichForExecutionSelector(
                    $this->contextBuilder->buildBullishContext(),
                    ['expected_r_multiple' => 2.5, 'entry_zone_width_pct' => 1.0, 'atr_pct_15m_bps' => 100.0]
                ),
                'expected' => '15m',
            ],
            [
                'name' => 'Drop to 5m - Low R multiple',
                'context' => $this->contextBuilder->enrichForExecutionSelector(
                    $this->contextBuilder->buildBullishContext(),
                    ['expected_r_multiple' => 1.5, 'entry_zone_width_pct' => 1.5, 'atr_pct_15m_bps' => 150.0]
                ),
                'expected' => '5m',
            ],
            [
                'name' => 'Forbid drop - Low ADX',
                'context' => $this->contextBuilder->enrichForExecutionSelector(
                    $this->contextBuilder->buildBullishContext(),
                    ['expected_r_multiple' => 1.5, 'adx_5m' => 15.0, 'spread_bps' => 5.0]
                ),
                'expected' => '15m', // Devrait rester sur 15m car ADX trop bas
            ],
            [
                'name' => 'Filters mandatory failed',
                'context' => $this->contextBuilder->enrichForExecutionSelector(
                    $this->contextBuilder->buildHighRsiContext(),
                    ['expected_r_multiple' => 2.5] // RSI > 70 devrait bloquer
                ),
                'expected' => 'NONE',
            ],
        ];
        
        foreach ($testCases as $testCase) {
            try {
                $decision = $this->executionSelector->decide($testCase['context']);
                $actualTf = $decision->executionTimeframe;
                $expectedTf = $testCase['expected'];
                $passed = $actualTf === $expectedTf;
                
                $result->addExecutionSelectorResult(new ExecutionSelectorTestResult(
                    $testCase['name'],
                    $passed,
                    $expectedTf,
                    $actualTf,
                    $testCase['context'],
                    $decision->meta ?? [],
                    $passed ? 'Décision correcte' : "Attendu {$expectedTf}, obtenu {$actualTf}"
                ));
            } catch (\Throwable $e) {
                $result->addExecutionSelectorResult(new ExecutionSelectorTestResult(
                    $testCase['name'],
                    false,
                    $testCase['expected'],
                    'ERROR',
                    $testCase['context'],
                    [],
                    "Erreur: {$e->getMessage()}"
                ));
            }
        }
    }

    /**
     * Construit les scénarios de test prédéfinis
     * @return TestScenario[]
     */
    private function buildTestScenarios(): array
    {
        return [
            new TestScenario(
                'Bullish 15m Long',
                'Tendance haussière sur 15m, validation long',
                '15m',
                'long',
                $this->contextBuilder->buildBullishContext('BTCUSDT', '15m'),
                [],
                '15m'
            ),
            new TestScenario(
                'Bearish 15m Short',
                'Tendance baissière sur 15m, validation short',
                '15m',
                'short',
                $this->contextBuilder->buildBearishContext('BTCUSDT', '15m'),
                [],
                '15m'
            ),
            new TestScenario(
                'Bullish 1h Long',
                'Tendance haussière sur 1h, validation long',
                '1h',
                'long',
                $this->contextBuilder->buildBullishContext('BTCUSDT', '1h'),
                [],
                null
            ),
            new TestScenario(
                'High RSI Block',
                'RSI élevé devrait bloquer la validation',
                '15m',
                'long',
                $this->contextBuilder->buildHighRsiContext('BTCUSDT', '15m'),
                [],
                null
            ),
        ];
    }
}

