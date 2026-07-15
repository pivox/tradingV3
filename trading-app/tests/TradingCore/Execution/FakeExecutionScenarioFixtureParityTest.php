<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Fake\FakeExecutionScenario;
use App\TradingCore\Execution\Fake\FakeExecutionScenarioFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeExecutionScenario::class)]
#[CoversClass(FakeExecutionScenarioFixtures::class)]
final class FakeExecutionScenarioFixtureParityTest extends TestCase
{
    public function testJsonRecipeScenariosMatchTheirPhpFactoriesExactly(): void
    {
        $factories = [];

        foreach (self::fixture()['scenarios'] as $row) {
            self::assertArrayHasKey('php_fixture', $row);
            self::assertIsString($row['php_fixture']);
            self::assertTrue(
                is_callable([FakeExecutionScenarioFixtures::class, $row['php_fixture']]),
                sprintf('Unknown Fake execution fixture factory "%s".', $row['php_fixture']),
            );
            self::assertNotContains($row['php_fixture'], $factories, 'Fixture factories must be unique.');
            $factories[] = $row['php_fixture'];

            /** @var FakeExecutionScenario $scenario */
            $scenario = FakeExecutionScenarioFixtures::{$row['php_fixture']}();
            unset($row['php_fixture']);

            self::assertSame([
                'name' => $scenario->name,
                'order_outcome' => $scenario->orderOutcome,
                'fill_ratio' => $scenario->fillRatio,
                'protection_outcome' => $scenario->protectionOutcome,
                'reject_reason' => $scenario->rejectReason,
                'quality_flags' => $scenario->qualityFlags,
                'fail_safe_action' => $scenario->failSafeAction,
            ], $row);
        }
    }

    /** @return array{schema_version:int,scope:string,scenarios:list<array<string,mixed>>} */
    private static function fixture(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fake-paper/demo-recipe-scenarios.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'The COMMON-005 JSON fixture must be readable.');

        /** @var array{schema_version:int,scope:string,scenarios:list<array<string,mixed>>} $fixture */
        $fixture = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        return $fixture;
    }
}
