<?php

namespace App\MtfValidator\ConditionLoader;

use App\Config\MtfValidationConfig;
use App\Indicator\Condition\ConditionInterface;
use App\MtfValidator\ConditionLoader\Cards\Rule\Rule;
use App\MtfValidator\ConditionLoader\Cards\Rule\Rules;
use App\MtfValidator\ConditionLoader\Cards\Validation\Validation;
use App\MtfValidator\Exception\MissingConditionException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

class ConditionRegistry
{
    /** @var array<string,Rule> */
    public static array $rules = [];

    private ?Rules $rulesCard = null;
    private ?Validation $validationCard = null;
    private ?MtfValidationConfig $currentConfig = null;

    /**
     * @param iterable<string,ConditionInterface> $allConditions  Iterable indexé par la clé (lazy)
     * @param ContainerInterface&ServiceProviderInterface $locator Locator indexé par la clé (lazy)
     */
    public function __construct(
        #[AutowireIterator('app.indicator.condition', indexAttribute: 'key')]
        private readonly iterable $allConditions,

        #[AutowireLocator('app.indicator.condition')]
        private readonly ContainerInterface $locator,
        private readonly LoggerInterface $mtfLogger,
    ) {
    }

    /** @return string[] Liste des clés disponibles (ordre ≈ priorité) */
    public function names(): array
    {
        if ($this->locator instanceof ServiceProviderInterface) {
            // getProvidedServices() retourne [key => class|'?class'] — on garde les clés
            return array_keys($this->locator->getProvidedServices());
        }

        // Fallback: on itère une fois pour récupérer les clés (sans instancier grâce au keyed iterator)
        $keys = [];
        foreach ($this->allConditions as $key => $unused) {
            $keys[] = (string) $key;
        }
        return $keys;
    }

    public function has(string $name): bool
    {
        // PSR-11: has() est implémenté par le ServiceLocator de Symfony
        return method_exists($this->locator, 'has') ? $this->locator->has($name) : in_array($name, $this->names(), true);
    }

    public function get(string $name): ?ConditionInterface
    {
        if (!$this->has($name)) {
            return null;
        }
        /** @var ConditionInterface $svc */
        $svc = $this->locator->get($name); // instanciation paresseuse de la condition ciblée
        return $svc;
    }

    /**
     * Évalue un sous-ensemble de conditions (ou toutes si $names = null).
     * @param array $context Contexte commun aux conditions.
     * @param string[]|null $names
     * @return array<string,array> map name => résultat normalisé (toArray())
     */
    public function evaluate(array $context, ?array $names = null): array
    {
        $out = [];

        if ($names) {
            foreach ($names as $name) {
                $cond = $this->get($name);
                if (!$cond) {
                    $rule = self::resolveRule($name);
                    if ($rule !== null) {
                        try {
                            $out[$name] = $rule->evaluate($context)->toArray();
                            continue;
                        } catch (\Throwable $e) {
                            $out[$name] = $this->errorPayload($name, $e);
                            continue;
                        }
                    }

                    throw MissingConditionException::forRule($name);
                }
                try {
                    $out[$name] = $cond->evaluate($context)->toArray();
                } catch (\Throwable $e) {
                    $out[$name] = $this->errorPayload($name, $e);
                }
            }
            return $out;
        }

        // Toutes les conditions (iteration paresseuse, indexées par clé)
        foreach ($this->allConditions as $name => $cond) {
            try {
                $out[(string) $name] = $cond->evaluate($context)->toArray();
            } catch (\Throwable $e) {
                $out[(string) $name] = $this->errorPayload((string) $name, $e);
            }
        }

        return $out;
    }

    /** Payload d'erreur homogène pour le logging/audit. */
    private function errorPayload(string $name, \Throwable $e): array
    {
        if (isset($this->mtfLogger)) {
            $this->mtfLogger->error('Condition evaluation failed', [
                'name' => $name,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'name' => $name,
            'passed' => false,
            'value' => null,
            'threshold' => null,
            'meta' => [
                'error' => true,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ],
        ];
    }

    public static function resolveCondition(string $name): ?ConditionInterface
    {
        throw new \LogicException('Use instance method get() instead of static resolveCondition()');
    }

    public static function resolveRule(string $name): ?Rule
    {
        return self::$rules[$name] ?? null;
    }

    public function hasCondition(string $name): bool
    {
        return $this->has($name) || isset(self::$rules[$name]);
    }

    public function load(array|MtfValidationConfig $config): void
    {
        $rulesData  = [];
        $validation = null;

        if ($config instanceof MtfValidationConfig) {
            // Nouveau format: règles depuis le fichier validations MTF
            $rulesData = $config->getRules();
            $validation = $config->getValidation();
            // Stocker le config actuel
            $this->currentConfig = $config;
        } elseif (\is_array($config)) {
            // Format array (pour tests/compatibilité)
            $root      = $config['mtf_validation'] ?? $config;
            $rulesData = $root['rules'] ?? [];
            $validation = $root['validation'] ?? null;
            // Pas de config pour les arrays
            $this->currentConfig = null;
        }

        // Charger les règles
        $this->rulesCard = (new Rules($this))->fill(['rules' => $rulesData]);
        self::$rules = $this->rulesCard->getRules();

        // Charger la validation si disponible
        if (!empty($validation)) {
            $this->validationCard = (new Validation($this->rulesCard, $this))->fill(['validation' => $validation]);
        }
    }

    /**
     * Charge les règles depuis MtfValidationConfig et la validation depuis un array.
     * Méthode helper pour services.yaml qui ne peut pas passer deux arguments.
     */
    public function loadFromConfigs(MtfValidationConfig $validationConfig): void
    {
        $this->load($validationConfig);
    }

    /**
     * Recharge le registry avec un nouveau config (pour changement de mode dynamique)
     * Met en cache les règles et la validation pour éviter de recharger inutilement
     * @param MtfValidationConfig $config Nouveau config à charger
     */
    public function reload(MtfValidationConfig $config): void
    {
        $this->load($config);
    }

    public function getValidation(): ?Validation
    {
        return $this->validationCard;
    }

    public function getRules(): ?Rules
    {
        return $this->rulesCard;
    }

    public function evaluateValidation(array $contextsByTimeframe): array
    {
        if (!$this->validationCard) {
            throw new \LogicException('Validation configuration not loaded. Call load() first.');
        }

        return $this->validationCard->evaluate($contextsByTimeframe);
    }

    /**
     * Retourne le config actuel (si chargé via MtfValidationConfig)
     * @return MtfValidationConfig|null
     */
    public function getCurrentConfig(): ?MtfValidationConfig
    {
        return $this->currentConfig;
    }
}
