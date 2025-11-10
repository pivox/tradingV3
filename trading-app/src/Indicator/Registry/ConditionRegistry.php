<?php

declare(strict_types=1);

namespace App\Indicator\Registry;

use App\Indicator\Condition\ConditionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

        #[Autowire(service: 'monolog.logger.indicators')] private readonly ?LoggerInterface $indicatorLogger = null,
        /** @var array<string,ContainerInterface&ServiceProviderInterface> */
        private readonly array $locatorsByTimeframe = [],
        /** @var array<string,ContainerInterface&ServiceProviderInterface> */
        private readonly array $locatorsByTimeframeSide = [],
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

    /** Noms disponibles pour un timeframe (et optionnellement side). */
    public function namesForTimeframe(string $timeframe, ?string $side = null): array
    {
        $locator = $this->locatorFor($timeframe, $side);
        if ($locator instanceof ServiceProviderInterface) {
            return array_keys($locator->getProvidedServices());
        }
        return [];
    }

    /** Évalue toutes les conditions pour un timeframe (optionnellement side). */
    public function evaluateForTimeframe(string $timeframe, array $context, ?string $side = null): array
    {
        $locator = $this->locatorFor($timeframe, $side);
        if (!$locator) {
            return [];
        }
        $out = [];
        $names = [];
        if ($locator instanceof ServiceProviderInterface) {
            $names = array_keys($locator->getProvidedServices());
        }
        foreach ($names as $name) {
            try {
                /** @var ConditionInterface $cond */
                $cond = $locator->get($name);
                $out[$name] = $cond->evaluate($context)->toArray();
            } catch (\Throwable $e) {
                $out[$name] = $this->errorPayload((string)$name, $e);
            }
        }
        return $out;
    }

    private function locatorFor(string $timeframe, ?string $side): ?ContainerInterface
    {
        if ($side) {
            $key = $timeframe . ':' . strtolower($side);
            if (isset($this->locatorsByTimeframeSide[$key])) {
                return $this->locatorsByTimeframeSide[$key];
            }
        }
        return $this->locatorsByTimeframe[$timeframe] ?? null;
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
        if ($this->indicatorLogger) {
            $this->indicatorLogger->error('Condition evaluation failed', [
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
