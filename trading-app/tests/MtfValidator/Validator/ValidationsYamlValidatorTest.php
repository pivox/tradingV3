<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Validator;

use App\Config\MtfValidationConfig;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use App\MtfValidator\Validator\ValidationsYamlValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ValidationsYamlValidatorTest extends TestCase
{
    private MtfValidationConfig $config;
    /** @var ConditionRegistry&MockObject */
    private ConditionRegistry $registry;

    protected function setUp(): void
    {
        $this->config = $this->createMock(MtfValidationConfig::class);
        $this->registry = $this->createMock(ConditionRegistry::class);
    }

    public function testValidateMissingRulesSection(): void
    {
        $this->config->method('getConfig')->willReturn([]);
        $this->config->method('getRules')->willReturn([]);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn([]);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('rules', $errors[0]->getMessage());
    }

    public function testValidateMissingRuleReference(): void
    {
        $rules = [
            'rule1' => ['any_of' => ['nonexistent_rule', 'another_missing']],
        ];

        $this->config->method('getConfig')->willReturn(['rules' => $rules, 'validation' => []]);
        $this->config->method('getRules')->willReturn($rules);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn([]);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $errorMessages = array_map(fn($e) => $e->getMessage(), $errors);
        $this->assertNotEmpty(array_filter($errorMessages, fn($m) => str_contains($m, 'nonexistent_rule')));
    }

    public function testValidateCircularReference(): void
    {
        $rules = [
            'rule_a' => ['any_of' => ['rule_b']],
            'rule_b' => ['any_of' => ['rule_a']],
        ];

        $this->config->method('getConfig')->willReturn(['rules' => $rules, 'validation' => []]);
        $this->config->method('getRules')->willReturn($rules);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn([]);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        // Note: La détection de référence circulaire nécessite que les règles soient définies
        // Dans ce cas, les règles existent mais créent une boucle
        // Le validateur devrait détecter cela
        $this->assertTrue($result->hasErrors() || $result->hasWarnings());
    }

    public function testValidateInvalidCustomOpRule(): void
    {
        $rules = [
            'invalid_op' => [
                'op' => 'invalid',
                'left' => 'field1',
                'right' => 0.0,
            ],
        ];

        $this->config->method('getConfig')->willReturn(['rules' => $rules, 'validation' => []]);
        $this->config->method('getRules')->willReturn($rules);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn([]);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $errorMessages = array_map(fn($e) => $e->getMessage(), $errors);
        $this->assertNotEmpty(array_filter($errorMessages, fn($m) => str_contains($m, 'invalid') || str_contains($m, 'opérateur')));
    }

    public function testValidateInvalidFieldComparisonRule(): void
    {
        $rules = [
            'invalid_fields' => [
                'lt_fields' => ['field1'], // Moins de 2 champs
            ],
        ];

        $this->config->method('getConfig')->willReturn(['rules' => $rules, 'validation' => []]);
        $this->config->method('getRules')->willReturn($rules);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn([]);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $errorMessages = array_map(fn($e) => $e->getMessage(), $errors);
        $this->assertNotEmpty(array_filter($errorMessages, fn($m) => str_contains($m, '2 champs')));
    }

    public function testValidateInvalidTrendRule(): void
    {
        $rules = [
            'invalid_trend' => [
                'increasing' => [
                    'field' => 'macd_hist',
                    'n' => -1, // Valeur invalide
                ],
            ],
        ];

        $this->config->method('getConfig')->willReturn(['rules' => $rules, 'validation' => []]);
        $this->config->method('getRules')->willReturn($rules);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn([]);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $errorMessages = array_map(fn($e) => $e->getMessage(), $errors);
        $this->assertNotEmpty(array_filter($errorMessages, fn($m) => str_contains($m, 'entier positif')));
    }

    public function testValidateExecutionSelectorMissingReference(): void
    {
        $config = [
            'rules' => [],
            'execution_selector' => [
                'stay_on_15m_if' => [
                    'nonexistent_condition',
                ],
            ],
            'validation' => [],
        ];

        $this->config->method('getConfig')->willReturn($config);
        $this->config->method('getRules')->willReturn([]);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn([]);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $errorMessages = array_map(fn($e) => $e->getMessage(), $errors);
        $this->assertNotEmpty(array_filter($errorMessages, fn($m) => str_contains($m, 'nonexistent_condition')));
    }

    public function testValidateFiltersMandatoryMissingReference(): void
    {
        $config = [
            'rules' => [],
            'filters_mandatory' => [
                'nonexistent_filter',
            ],
            'validation' => [],
        ];

        $this->config->method('getConfig')->willReturn($config);
        $this->config->method('getRules')->willReturn([]);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn([]);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $errorMessages = array_map(fn($e) => $e->getMessage(), $errors);
        $this->assertNotEmpty(array_filter($errorMessages, fn($m) => str_contains($m, 'nonexistent_filter')));
    }

    public function testValidateValidConfig(): void
    {
        $rules = [
            'rule1' => ['lt' => 70],
            'rule2' => ['any_of' => ['rule1']],
        ];

        $config = [
            'rules' => $rules,
            'execution_selector' => [
                'stay_on_15m_if' => ['rule1'],
            ],
            'filters_mandatory' => ['rule1'],
            'validation' => [
                'timeframe' => [
                    '15m' => [
                        'long' => [
                            ['all_of' => ['rule1']],
                        ],
                    ],
                ],
            ],
        ];

        $this->config->method('getConfig')->willReturn($config);
        $this->config->method('getRules')->willReturn($rules);
        $this->config->method('getValidation')->willReturn($config['validation']);
        $this->registry->method('names')->willReturn(['some_condition']);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        // Devrait être valide car toutes les références existent
        $this->assertFalse($result->hasErrors());
    }

    public function testValidateExecutionSelectorInvalidValueType(): void
    {
        $config = [
            'rules' => [],
            'execution_selector' => [
                'stay_on_15m_if' => [
                    ['some_condition' => 'invalid_string'], // Doit être numérique ou booléen
                ],
            ],
            'validation' => [],
        ];

        $this->config->method('getConfig')->willReturn($config);
        $this->config->method('getRules')->willReturn([]);
        $this->config->method('getValidation')->willReturn([]);
        $this->registry->method('names')->willReturn(['some_condition']);

        $validator = new ValidationsYamlValidator($this->config, $this->registry);
        $result = $validator->validate();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getErrors();
        $errorMessages = array_map(fn($e) => $e->getMessage(), $errors);
        $this->assertNotEmpty(array_filter($errorMessages, fn($m) => str_contains($m, 'numérique') || str_contains($m, 'booléen')));
    }
}

