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

        $catalog = (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 4) . '/config/trading/condition_catalog/1.0.0.yaml',
        );
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
                sprintf('Executable condition "%s" has no runtime implementation.', $conditionId),
            );
        }
    }
}
