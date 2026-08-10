<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Catalog;

use App\TradingCore\Rules\Catalog\ConditionCatalogException;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Evaluation\StrictCompiledExpressionEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ConditionCatalogLoader::class)]
final class ConditionCatalogLoaderTest extends TestCase
{
    private string $catalogPath;
    private string $setupRoot;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        $this->catalogPath = $root . '/config/trading/condition_catalog/1.0.0.yaml';
        $this->setupRoot = $root . '/config/trading/setup_contract';
    }

    public function testRealCatalogExactlyCoversConditionsReferencedByPublishedSetups(): void
    {
        $catalog = (new ConditionCatalogLoader())->loadFile($this->catalogPath);
        $referenced = [];
        foreach (glob($this->setupRoot . '/*/*.yaml') ?: [] as $path) {
            $this->collectConditionIds(Yaml::parseFile($path), $referenced);
        }
        $referenced = array_keys($referenced);
        sort($referenced, SORT_STRING);

        self::assertSame('condition-catalog.v1', $catalog->schemaVersion);
        self::assertSame('1.0.0', $catalog->catalogVersion);
        self::assertCount(60, $catalog->conditionIds());
        self::assertSame([], array_values(array_diff($referenced, $catalog->conditionIds())));
        self::assertSame([], array_values(array_diff(StrictCompiledExpressionEvaluator::referencedConditionIds(), $catalog->conditionIds())));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $catalog->stableHash());
        self::assertSame($catalog->stableHash(), (new ConditionCatalogLoader())->loadFile($this->catalogPath)->stableHash());
    }

    public function testCanonicalHashDoesNotDependOnDefinitionOrParameterMappingOrder(): void
    {
        $loader = new ConditionCatalogLoader();
        $document = $this->validDocument();
        $reordered = $document;
        $reordered['conditions'] = array_reverse($reordered['conditions']);
        $reordered['conditions'][0] = array_reverse($reordered['conditions'][0], true);
        $reordered['conditions'][0]['parameters'] = array_reverse($reordered['conditions'][0]['parameters'], true);

        self::assertSame($loader->load($document)->stableHash(), $loader->load($reordered)->stableHash());

        $changedFreshness = $document;
        $changedFreshness['input_freshness_seconds']['indicator_snapshot']['15m']++;
        self::assertNotSame($loader->load($document)->stableHash(), $loader->load($changedFreshness)->stableHash());
    }

    public function testEveryReferencedYamlCompositeUsesTheCompiledAuthority(): void
    {
        $catalog = (new ConditionCatalogLoader())->loadFile($this->catalogPath);
        $compositeIds = [];
        foreach (glob(dirname(__DIR__, 4) . '/src/MtfValidator/config/validations.*.yaml') ?: [] as $path) {
            $document = Yaml::parseFile($path);
            self::assertIsArray($document);
            foreach ($document['mtf_validation']['rules'] ?? [] as $conditionId => $definition) {
                if (!is_string($conditionId)
                    || !in_array($conditionId, $catalog->conditionIds(), true)
                    || !is_array($definition)
                    || (!array_key_exists('all_of', $definition) && !array_key_exists('any_of', $definition))) {
                    continue;
                }
                $compositeIds[$conditionId] = true;
            }
        }

        self::assertCount(18, $compositeIds);
        foreach (array_keys($compositeIds) as $conditionId) {
            self::assertSame(
                'compiled_expression:' . $conditionId,
                $catalog->definition($conditionId)->implementation,
                sprintf('YAML composite "%s" must not collide with a PHP condition service.', $conditionId),
            );
        }
    }

    public function testRejectsDuplicateIdsUnknownFieldsEmptyCompatibilityAndUnsafeMissingPolicy(): void
    {
        $loader = new ConditionCatalogLoader();

        $duplicate = $this->validDocument();
        $duplicate['conditions'][] = $duplicate['conditions'][0];
        $this->assertRejected($duplicate, 'Duplicate condition id');

        $unknown = $this->validDocument();
        $unknown['conditions'][0]['fallback'] = true;
        $this->assertRejected($unknown, 'Unknown field "fallback"');

        $emptyTimeframes = $this->validDocument();
        $emptyTimeframes['conditions'][0]['timeframes'] = [];
        $this->assertRejected($emptyTimeframes, 'timeframes must be a non-empty list');

        $unsafe = $this->validDocument();
        $unsafe['conditions'][0]['missing_data_policy'] = 'pass';
        $this->assertRejected($unsafe, 'missing_data_policy must be reject');

        $missingFreshness = $this->validDocument();
        $missingFreshness['input_freshness_seconds']['indicator_snapshot'] = ['5m' => 480];
        $this->assertRejected($missingFreshness, 'has no freshness contract');

        $invalidFreshness = $this->validDocument();
        $invalidFreshness['input_freshness_seconds']['indicator_snapshot']['15m'] = -1;
        $this->assertRejected($invalidFreshness, 'must be a non-negative integer');
    }

    public function testRejectsAmbiguousSeriesAndInvalidParameterSchema(): void
    {
        $loader = new ConditionCatalogLoader();
        $series = $this->validDocument();
        $series['conditions'][0]['value_type'] = 'series<number>';
        $series['conditions'][0]['series_order'] = 'scalar';
        $this->assertRejected($series, 'series_order must be oldest_to_newest');

        $parameter = $this->validDocument();
        $parameter['conditions'][0]['parameters']['threshold']['type'] = 'floatish';
        $this->assertRejected($parameter, 'Unsupported parameter type');

        $defaultBelowMin = $this->validDocument();
        $defaultBelowMin['conditions'][0]['parameters']['threshold']['default'] = -1.0;
        $this->assertRejected($defaultBelowMin, 'default is below minimum');

        $defaultOutsideEnum = $this->validDocument();
        $defaultOutsideEnum['conditions'][0]['parameters']['threshold']['values'] = [60.0, 80.0];
        $this->assertRejected($defaultOutsideEnum, 'default is outside its enum');
    }

    /** @return array<string, mixed> */
    private function validDocument(): array
    {
        return [
            'schema_version' => 'condition-catalog.v1',
            'catalog_version' => '1.0.0',
            'input_freshness_seconds' => [
                'indicator_snapshot' => ['15m' => 1_200],
                'timestamped_order_book' => ['1m' => 5],
            ],
            'conditions' => [
                [
                    'id' => 'rsi_lt_70',
                    'implementation' => 'condition_service:rsi_lt_70',
                    'metric' => 'rsi',
                    'unit' => 'index',
                    'value_type' => 'number',
                    'timeframes' => ['global', '15m'],
                    'sides' => ['long', 'short'],
                    'context_source' => 'indicator_snapshot',
                    'series_order' => 'scalar',
                    'missing_data_policy' => 'reject',
                    'parameters' => [
                        'threshold' => ['type' => 'number', 'required' => false, 'default' => 70.0, 'min' => 0, 'max' => 100],
                    ],
                    'provenance' => 'App\\Indicator\\Condition\\RsiLt70Condition',
                    'status' => 'executable',
                ],
                [
                    'id' => 'spread_bps_lte',
                    'implementation' => 'blocked:spread_bps_lte',
                    'metric' => 'spread_bps',
                    'unit' => 'basis_points',
                    'value_type' => 'number',
                    'timeframes' => ['1m'],
                    'sides' => ['long', 'short'],
                    'context_source' => 'timestamped_order_book',
                    'series_order' => 'scalar',
                    'missing_data_policy' => 'reject',
                    'parameters' => [],
                    'provenance' => 'issue:#303',
                    'status' => 'blocked',
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $document */
    private function assertRejected(array $document, string $message): void
    {
        try {
            (new ConditionCatalogLoader())->load($document);
            self::fail('Expected condition catalogue rejection.');
        } catch (ConditionCatalogException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @param array<string, true> $ids */
    private function collectConditionIds(mixed $node, array &$ids): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['condition']) && is_string($node['condition'])) {
            $ids[$node['condition']] = true;
        }
        foreach ($node as $value) {
            $this->collectConditionIds($value, $ids);
        }
    }
}
