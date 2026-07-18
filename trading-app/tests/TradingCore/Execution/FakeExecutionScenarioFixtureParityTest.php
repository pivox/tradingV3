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

    public function testPrivateWsFixtureKeepsDeclaredDeliveryOrderAndConflictCaseSeparate(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fake-paper/private-ws-out-of-order-v1.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'The private WS out-of-order fixture must be readable.');

        /** @var array<string,mixed> $fixture */
        $fixture = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        self::assertSame('fake-private-ws-out-of-order-v1', $fixture['schema_version'] ?? null);
        self::assertSame(
            ['delivery-1', 'duplicate-1', 'delivery-3', 'delivery-2'],
            array_column($fixture['scenario']['deliveries'] ?? [], 'fixture_entry_id'),
        );
        self::assertSame(
            ['1', '1', '3', '2'],
            array_map(
                static fn (array $delivery): string => (string) ($delivery['sequence'] ?? ''),
                $fixture['scenario']['deliveries'] ?? [],
            ),
        );
        self::assertSame('1', (string) ($fixture['conflict_scenario']['deliveries'][1]['sequence'] ?? ''));
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
