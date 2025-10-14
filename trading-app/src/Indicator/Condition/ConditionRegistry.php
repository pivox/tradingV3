<?php

namespace App\Indicator\Condition;

use Psr\Log\LoggerInterface;
use App\Indicator\Condition\ConditionInterface;

/**
 * Registre des conditions découvertes via le tag "app.indicator.condition".
 * Permet lookup par nom (clé YAML) + itération.
 */
final class ConditionRegistry
{
    /** @var array<string,ConditionInterface> */
    private array $byName = [];
    /** @var string[] */
    private array $duplicates = [];

    /** @param iterable<ConditionInterface> $conditions */
    public function __construct(iterable $conditions, ?LoggerInterface $logger = null)
    {
        foreach ($conditions as $cond) {
            $name = $cond->getName();
            if (isset($this->byName[$name])) {
                $this->duplicates[] = $name;
                // On remplace pas; on garde la première, log seulement
                if ($logger) {
                    $logger->warning('Duplicate condition name detected', ['name' => $name, 'class' => get_class($cond)]);
                }
                continue;
            }
            $this->byName[$name] = $cond;
        }
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->byName);
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    public function get(string $name): ?ConditionInterface
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * Évalue un sous-ensemble de conditions (ou toutes si $names = null).
     * @param array $context Contexte commun aux conditions.
     * @param string[]|null $names
     * @return array<string,array> map name => résultat normalisé
     */
    public function evaluate(array $context, ?array $names = null): array
    {
        $targets = $names ? array_intersect_key($this->byName, array_flip($names)) : $this->byName;
        $out = [];
        foreach ($targets as $name => $cond) {
            try {
                $res = $cond->evaluate($context); // ConditionResult
                $out[$name] = $res->toArray();
            } catch (\Throwable $e) {
                $out[$name] = [
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
        }
        return $out;
    }

    /** @return string[] noms en double détectés (diagnostic). */
    public function duplicates(): array
    {
        return $this->duplicates;
    }
}
