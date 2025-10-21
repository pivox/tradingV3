<?php

namespace App\Indicator\ConditionLoader;

use App\Config\MtfValidationConfig;
use App\Indicator\ConditionLoader\Cards\Rule\Rule;
use App\Indicator\ConditionLoader\Cards\Rule\Rules;
use App\Indicator\ConditionLoader\Cards\Validation\Validation;
use Psr\Log\LoggerInterface;

class ConditionRegistry
{
    /** @var array<string,\App\Indicator\Condition\ConditionInterface> */
    public static array $conditions = [];
    /** @var array<string,Rule> */
    public static array $rules = [];

    private ?Rules $rulesCard = null;
    private ?Validation $validationCard = null;

    public function __construct(
        private iterable $indicatorConditions,
        private ?LoggerInterface $logger = null
    ) {
        if (empty(self::$conditions)) {
            foreach ($this->indicatorConditions as $condition) {
                self::$conditions[$condition->getName()] = $condition;
            }
        }
    }

    public static function resolveCondition(string $name): ?\App\Indicator\Condition\ConditionInterface
    {
        return self::$conditions[$name] ?? null;
    }

    public static function resolveRule(string $name): ?Rule
    {
        return self::$rules[$name] ?? null;
    }

    public function hasCondition(string $name): bool
    {
        return isset(self::$conditions[$name]) || isset(self::$rules[$name]);
    }

    public function load(array|MtfValidationConfig $config): void
    {
        if ($config instanceof MtfValidationConfig) {
            $config = $config->getConfig();
        }

        $root = $config['mtf_validation'] ?? $config;

        $this->rulesCard = (new Rules())->fill($root);
        self::$rules = $this->rulesCard->getRules();

        $this->validationCard = (new Validation($this->rulesCard))->fill($root);
    }

    public function getValidation(): ?Validation
    {
        return $this->validationCard;
    }

    public function getRules(): ?Rules
    {
        return $this->rulesCard;
    }

    public function evaluate(array $contextsByTimeframe): array
    {
        if (!$this->validationCard) {
            throw new \LogicException('Validation configuration not loaded. Call load() first.');
        }

        return $this->validationCard->evaluate($contextsByTimeframe);
    }
}
