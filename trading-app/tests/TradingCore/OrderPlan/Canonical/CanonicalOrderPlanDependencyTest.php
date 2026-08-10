<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CanonicalOrderPlanDependencyTest extends TestCase
{
    public function testCanonicalPipelineHasNoLegacyOrRuntimeFrameworkDependency(): void
    {
        $directory = \dirname(__DIR__, 4) . '/src/TradingCore/OrderPlan/Canonical';
        $files = glob($directory . '/*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            foreach ([
                'App\\TradeEntry\\',
                'LegacyOrderPlan',
                'ExecutionBox',
                'Doctrine\\',
                'Symfony\\Component\\Messenger\\',
                'App\\Provider\\',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, basename($file));
            }
        }
    }
}
