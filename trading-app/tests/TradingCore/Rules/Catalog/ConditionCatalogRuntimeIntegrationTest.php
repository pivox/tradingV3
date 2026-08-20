<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Catalog;

use App\Indicator\Registry\ConditionRegistry;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Evaluation\StrictCompiledExpressionEvaluator;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class ConditionCatalogRuntimeIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    public function testEveryExecutableCatalogImplementationIsAvailableAtRuntime(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ConditionRegistry::class);
        self::assertInstanceOf(ConditionRegistry::class, $registry);
        $serviceIds = $registry->names();
        $expressionIds = StrictCompiledExpressionEvaluator::supportedIds();

        foreach (['1.0.0', '1.1.0', '1.2.0'] as $version) {
            $catalog = (new ConditionCatalogLoader())->loadVersion($version);
            foreach ($catalog->conditionIds() as $conditionId) {
                $definition = $catalog->definition($conditionId);
                if ($definition->status !== 'executable') {
                    continue;
                }

                [$implementationType, $implementationId] = explode(':', $definition->implementation, 2);
                $availableIds = match ($implementationType) {
                    'condition_service' => $serviceIds,
                    'compiled_expression' => $expressionIds,
                    default => [],
                };

                self::assertContains(
                    $implementationId,
                    $availableIds,
                    sprintf('Executable condition "%s" in %s has no runtime implementation.', $conditionId, $version),
                );
            }
        }
    }

    public function testEveryExecutableCatalogConditionRejectsAnEmptyInputDirectly(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ConditionRegistry::class);
        self::assertInstanceOf(ConditionRegistry::class, $registry);
        $expressions = new StrictCompiledExpressionEvaluator(new \App\TradingCore\Rules\Evaluation\StrictConditionRegistry(
            array_values(array_filter(array_map(
                static fn (string $name) => $registry->get($name),
                $registry->names(),
            ))),
        ));
        foreach (['1.0.0', '1.1.0', '1.2.0'] as $version) {
            $catalog = (new ConditionCatalogLoader())->loadVersion($version);
            foreach ($catalog->conditionIds() as $conditionId) {
                $definition = $catalog->definition($conditionId);
                if ($definition->status !== 'executable') {
                    continue;
                }
                $result = str_starts_with($definition->implementation, 'condition_service:')
                    ? $registry->get($conditionId)?->evaluate([])
                    : $expressions->evaluate($conditionId, []);

                self::assertNotNull($result, sprintf('Condition "%s" in %s has no direct evaluator.', $conditionId, $version));
                self::assertSame($conditionId, $result->name);
                self::assertFalse($result->passed, sprintf('Condition "%s" in %s fails open on empty input.', $conditionId, $version));
            }
        }
    }
}
