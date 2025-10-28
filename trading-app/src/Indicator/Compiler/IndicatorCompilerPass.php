<?php
// src/Indicator/Compiler/IndicatorCompilerPass.php
declare(strict_types=1);

namespace App\Indicator\Compiler;

use App\Indicator\Attribute\AsIndicatorCondition;
use App\Contracts\Indicator\Conditions\ConditionInterface;
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

            // Chaque $tags[] correspond à 1 annotation/tag (Symfony agrège les attributs)
            foreach ($tags as $tag) {
                // Métadonnées injectées par autoconfigure (voir services.yaml plus bas)
                $timeframes = $tag['timeframes'] ?? [];
                $side      = $tag['side'] ?? null;
                $name      = $tag['name'] ?? null;
                $priority  = (int)($tag['priority'] ?? 0);

                if (!$timeframes || !is_array($timeframes)) {
                    throw new InvalidArgumentException(sprintf(
                        'Service "%s": attribute "timeframes" is required and must be array',
                        $serviceId
                    ));
                }

                // On dérive un nom si non fourni (FQCN court)
                if (!$name) {
                    $short = ($pos = strrpos($class, '\\')) !== false ? substr($class, $pos + 1) : $class;
                    $name = preg_replace('/Condition$/', '', $short) ?: $short;
                }

                // On retient (priority, Reference) pour trier après
                foreach ($timeframes as $tf) {
                    $byTf[$tf][$name][] = ['p' => $priority, 'ref' => new Reference($serviceId)];
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
        $registryDef->setArgument(0, $locatorsByTf);
        $registryDef->setArgument(1, $locatorsByTfSide);
    }
}
