<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Microstructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CanonicalMicrostructurePhpCompatibilityTest extends TestCase
{
    public function testProductionPathUsesOnlyPhp83AvailableArrayFunctions(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        foreach ([
            '/Trading/Paper/Backtesting/PaperBacktestDatasetAdapter.php',
            '/TradingCore/Microstructure/CanonicalMicrostructureEngine.php',
            '/TradingCore/Microstructure/CanonicalMicrostructureSnapshot.php',
        ] as $relativePath) {
            $source = file_get_contents($root . $relativePath);
            self::assertIsString($source);
            self::assertStringNotContainsString('array_any(', $source, $relativePath);
        }
    }
}
