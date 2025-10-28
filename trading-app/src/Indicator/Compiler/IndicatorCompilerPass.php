<?php
// src/Indicator/Compiler/IndicatorCompilerPass.php
declare(strict_types=1);

namespace App\Indicator\Compiler;

use App\Indicator\Attribute\AsIndicatorCondition;
use App\Indicator\Condition\ConditionInterface;
use Symfony\Component\DependencyInjection\{Compiler\CompilerPassInterface, ContainerBuilder, Reference};
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

final class IndicatorCompilerPass implements CompilerPassInterface
{
    private const TAG = 'app.indicator.condition';

    public function process(ContainerBuilder $container): void
    {
        // Récupération de tous les services tagués
        $definitions = $container->findTaggedServiceIds(self::TAG, true);

        // Maps: '15m' => ['RsiLt70' => ref, 'MacdHistGt0' => ref, ...]
        $byTf = [];
        // Maps: '15m:long' => ['RsiLt70' => ref, ...]
        $byTfSide = [];

        foreach ($definitions as $serviceId => $tags) {
            $def = $container->getDefinition($serviceId);

            // Sanity check: implémente bien ConditionInterface
            $class = $def->getClass();
            if ($class === null || !is_subclass_of($class, ConditionInterface::class)) {
                throw new InvalidArgumentException(sprintf(
                    'Service "%s" must implement %s',
                    $serviceId,
                    ConditionInterface::class
                ));
            }

            // Lis les métadonnées directement depuis l'attribut (fiable, sans config d'autoconfigure spécifique)
            $refl = new \ReflectionClass($class);
            $attrs = $refl->getAttributes(AsIndicatorCondition::class);
            if (empty($attrs)) {
                // Si pas d'attribut, on ignore ce service (il peut être taggé legacy)
                continue;
            }

            foreach ($attrs as $attr) {
                /** @var AsIndicatorCondition $meta */
                $meta = $attr->newInstance();
                $timeframes = $meta->timeframes ?? [];
                $side      = $meta->side ?? null;
                $name      = $meta->name ?? null;
                $priority  = (int)($meta->priority ?? 0);

                if (!$timeframes || !is_array($timeframes)) {
                    throw new InvalidArgumentException(sprintf(
                        'Service "%s": AsIndicatorCondition->timeframes is required and must be array',
                        $serviceId
                    ));
                }

                if (!$name) {
                    // derive from constant NAME or class short name
                    if (defined($class.'::NAME')) {
                        $name = constant($class.'::NAME');
                    } else {
                        $short = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
                        $name = preg_replace('/Condition$/', '', $short) ?: $short;
                    }
                }

                foreach ($timeframes as $tf) {
                    // Neutral conditions (no side) go to the neutral timeframe locator
                    if (!$side) {
                        $byTf[$tf][$name][] = ['p' => $priority, 'ref' => new Reference($serviceId)];
                    }
                    // Side-specific conditions only populate the timeframe+side locator
                    if ($side) {
                        $byTfSide["$tf:$side"][$name][] = ['p' => $priority, 'ref' => new Reference($serviceId)];
                    }
                }
            }
        }

        // Tri par priorité (desc) et réduction à une seule ref par nom
        $reduce = function(array $grouped): array {
            $result = [];
            foreach ($grouped as $name => $entries) {
                usort($entries, fn($a, $b) => $b['p'] <=> $a['p']);
                $result[$name] = $entries[0]['ref']; // garde la plus haute priorité
            }
            return $result;
        };

        $locatorsByTf = [];
        foreach ($byTf as $tf => $grouped) {
            $locatorsByTf[$tf] = ServiceLocatorTagPass::register($container, $reduce($grouped));
        }

        $locatorsByTfSide = [];
        foreach ($byTfSide as $key => $grouped) {
            $locatorsByTfSide[$key] = ServiceLocatorTagPass::register($container, $reduce($grouped));
        }

        // Injecte dans le ConditionRegistry
        $registryDef = $container->findDefinition(\App\Indicator\Registry\ConditionRegistry::class);
        // arguments 3 et 4 (après iterator, locator, logger)
        $registryDef->setArgument(3, $locatorsByTf);
        $registryDef->setArgument(4, $locatorsByTfSide);
    }
}
