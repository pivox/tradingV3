<?php

declare(strict_types=1);

namespace App\Indicator\Registry;

use App\Indicator\Condition\ConditionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Registre des conditions taggées "app.indicator.condition"
 * - Lookup par nom (clé YAML) via Service Locator (lazy, par clé)
 * - Itération sur toutes les conditions via Iterator (lazy)
 *
 * NB: la “clé YAML” est définie dans chaque classe de condition avec:
 *   #[AsTaggedItem(index: self::NAME)]
 */
final class ConditionRegistry
{
    /**
     * @param iterable<string,ConditionInterface> $allConditions  Iterable indexé par la clé (lazy)
     * @param ContainerInterface&ServiceProviderInterface $locator Locator indexé par la clé (lazy)
     */
    public function __construct(
        #[AutowireIterator('app.indicator.condition', indexAttribute: 'key')]
        private readonly iterable $allConditions,

        #[AutowireLocator('app.indicator.condition')]
        private readonly ContainerInterface $locator,

        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return string[] Liste des clés disponibles (ordre ≈ priorité) */
    public function names(): array
    {
        // Si le locator expose ses services (cas du ServiceLocator Symfony), on l’utilise
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
                    $out[$name] = [
                        'name' => $name,
                        'passed' => false,
                        'value' => null,
                        'threshold' => null,
                        'meta' => ['error' => true, 'message' => 'Condition introuvable'],
                    ];
                    continue;
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

    /** Payload d’erreur homogène pour le logging/audit. */
    private function errorPayload(string $name, \Throwable $e): array
    {
        if ($this->logger) {
            $this->logger->error('Condition evaluation failed', [
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
}
