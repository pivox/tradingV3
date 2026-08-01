<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Setup;

use App\TradingCore\Setup\Exception\SetupContractException;
use App\TradingCore\Setup\ConditionCatalog;
use App\TradingCore\Setup\SetupCompiler;
use App\TradingCore\Setup\SetupContract;
use App\TradingCore\Setup\SetupContractLoader;
use App\TradingCore\Setup\SetupContractValidator;
use Opis\JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(SetupContract::class)]
#[CoversClass(SetupContractLoader::class)]
#[CoversClass(SetupContractValidator::class)]
#[CoversClass(SetupCompiler::class)]
#[CoversClass(ConditionCatalog::class)]
#[CoversClass(SetupContractException::class)]
final class SetupContractLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3) . '/config/trading/setup_contract';
    }

    public function testLoadsExactlyTheFrozenEightSourceHypotheses(): void
    {
        $expected = [
            'day_trading.trend_continuation.long' => 'draft',
            'day_trading.trend_continuation.short' => 'blocked',
            'scalping.trend_continuation.long' => 'draft',
            'scalping.pullback.long' => 'draft',
            'scalping.trend_momentum.short' => 'draft',
            'micro_scalping.momentum_ofi.long' => 'blocked',
            'micro_scalping.momentum_ofi.short' => 'blocked',
            'crash_short' => 'draft',
        ];

        self::assertCount(8, glob($this->root . '/*/1.0.0.yaml') ?: []);
        $loader = new SetupContractLoader($this->root);
        foreach ($expected as $id => $status) {
            $contract = $loader->load($id, '1.0.0');
            self::assertSame($id, $contract->setupId);
            self::assertSame($status, $contract->status);
            self::assertFalse($contract->isExecutable());
        }
    }

    public function testCatalogContainsNoSwingOrNinthCrashPullbackSetup(): void
    {
        $paths = glob($this->root . '/*/1.0.0.yaml') ?: [];
        $ids = array_map(static fn (string $path): mixed => Yaml::parseFile($path)['setup_id'] ?? null, $paths);

        self::assertStringNotContainsString('swing', implode(' ', array_map('strval', $ids)));
        self::assertNotContains('crash_pullback', $ids);
        self::assertCount(8, array_unique($ids));
    }

    public function testSourceOriginsPinExactCurrentContentHashes(): void
    {
        $expected = [
            'validations.regular.yaml' => 'e15ec9ea51330c83b2d0f14791a7985fc06793ee925c32eb4d5d962b3b2e1a13',
            'validations.scalper.yaml' => '5bf86ce415079ee896a98d2c91e987d11db975c986500862b0cff82440c590a2',
            'validations.scalper_micro.yaml' => '47969bd5055b28ba5871b0b22e503482730a368c56fad3d0963aaad3808808e2',
            'validations.crash.yaml' => '5dd5cbf03cdbcb804cd664e47c0dce4007438bbce973af027a05e7155b2c10e2',
        ];

        foreach (glob($this->root . '/*/1.0.0.yaml') ?: [] as $path) {
            $document = Yaml::parseFile($path);
            self::assertIsArray($document);
            $origin = $document['source_origin'];
            $basename = basename($origin['file']);
            self::assertSame($expected[$basename], $origin['content_sha256'], $path);
            $sourcePath = dirname(__DIR__, 3) . '/' . $origin['file'];
            self::assertSame($origin['content_sha256'], hash_file('sha256', $sourcePath), $sourcePath);
            self::assertMatchesRegularExpression('/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/', $origin['line_range']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $origin['commit']);
        }
    }

    public function testSideIsIdenticalAcrossSetupContextAndExecution(): void
    {
        foreach (glob($this->root . '/*/1.0.0.yaml') ?: [] as $path) {
            $contract = SetupContract::fromDocument($this->yaml($path));
            self::assertSame($contract->side, $contract->toArray()['context']['side']);
            self::assertSame($contract->side, $contract->toArray()['execution']['side']);
        }
    }

    public function testRejectsSideMismatch(): void
    {
        $document = $this->yaml($this->root . '/day_trading.trend_continuation.long/1.0.0.yaml');
        $document['execution']['side'] = 'short';

        $this->expectException(SetupContractException::class);
        $this->expectExceptionMessage('setup.side=context.side=execution.side');
        (new SetupContractValidator())->validate($document);
    }

    public function testRejectsLegacyAliasesUnknownVersionsExtraFieldsAndSetupOwnedRisk(): void
    {
        $loader = new SetupContractLoader($this->root);
        foreach ([['regular.long', '1.0.0'], ['scalping.pullback.long', 'latest']] as [$id, $version]) {
            try {
                $loader->load($id, $version);
                self::fail('Expected identity rejection.');
            } catch (SetupContractException) {
                self::addToAssertionCount(1);
            }
        }

        $document = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $document['leverage_cap'] = 12;
        $this->expectException(SetupContractException::class);
        $this->expectExceptionMessage('Unknown field "leverage_cap"');
        (new SetupContractValidator())->validate($document);
    }

    public function testUnknownConditionIsACompilationFailure(): void
    {
        $contract = (new SetupContractLoader($this->root))->load('scalping.pullback.long', '1.0.0');

        $this->expectException(SetupContractException::class);
        $this->expectExceptionMessage('Unknown condition "invented_condition"');
        (new SetupCompiler())->compile($contract, new ConditionCatalog(['invented_condition']));
    }

    public function testImmutableSnapshotHasStableHashesVersionsAndProvenanceByKey(): void
    {
        $contract = (new SetupContractLoader($this->root))->load('scalping.pullback.long', '1.0.0');
        $first = (new SetupCompiler())->compile($contract);
        $second = (new SetupCompiler())->compile($contract);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame('scalping.pullback.long', $first->setupId);
        self::assertSame('1.0.0', $first->setupVersion);
        self::assertSame(['scalping' => '1.0.0'], $first->modeVersions);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->configHash);
        self::assertNull($first->conditionCatalogHash);
        self::assertFalse($first->publishable);
        self::assertArrayHasKey('context.trigger', $first->provenanceByKey);
    }

    public function testMicroMissingConditionsAndCrashCompatibilityRemainVisibleBlockers(): void
    {
        $loader = new SetupContractLoader($this->root);
        foreach (['micro_scalping.momentum_ofi.long', 'micro_scalping.momentum_ofi.short'] as $id) {
            $document = $loader->load($id, '1.0.0')->toArray();
            self::assertContains('spread_bps_lte', $document['data_condition_contract']['missing_conditions']);
            self::assertNotEmpty(array_filter(
                $document['data_condition_contract']['missing_conditions'],
                static fn (string $condition): bool => str_starts_with($condition, 'order_flow_imbalance_'),
            ));
        }
        $crash = $loader->load('crash_short', '1.0.0')->toArray();
        self::assertSame([], $crash['compatible_modes']);
        self::assertSame('unresolved', $crash['mode_compatibility']['state']);
        self::assertSame('#310', $crash['mode_compatibility']['issue']);
    }

    public function testDraft202012SchemaHasParityWithPhpValidator(): void
    {
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json');
        self::assertSame('https://json-schema.org/draft/2020-12/schema', get_object_vars($schema)['$schema'] ?? null);
        $jsonValidator = new JsonSchemaValidator();
        $phpValidator = new SetupContractValidator();

        foreach (glob($this->root . '/*/1.0.0.yaml') ?: [] as $path) {
            $array = $this->yaml($path);
            $object = json_decode(json_encode($array, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($jsonValidator->validate($object, $schema)->isValid(), $path);
            $phpValidator->validate($array);
        }

        $invalid = $this->yaml($this->root . '/day_trading.trend_continuation.long/1.0.0.yaml');
        $invalid['context']['side'] = 'short';
        $invalidObject = json_decode(json_encode($invalid, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($jsonValidator->validate($invalidObject, $schema)->isValid());
    }

    public function testIndependentValidAndInvalidFixturesExerciseSchemaAndPhpParity(): void
    {
        $fixtureRoot = dirname(__DIR__, 3) . '/tests/Fixtures/TradingCore/Setup';
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json');
        $valid = $this->jsonObject($fixtureRoot . '/valid-scalping-pullback.json');
        $invalid = $this->jsonObject($fixtureRoot . '/invalid-legacy-alias.json');
        $jsonValidator = new JsonSchemaValidator();

        self::assertTrue($jsonValidator->validate($valid, $schema)->isValid());
        self::assertFalse($jsonValidator->validate($invalid, $schema)->isValid());
        $validArray = json_decode(json_encode($valid, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($validArray);
        (new SetupContractValidator())->validate($validArray);
        $this->expectException(SetupContractException::class);
        $invalidArray = json_decode(json_encode($invalid, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($invalidArray);
        (new SetupContractValidator())->validate($invalidArray);
    }

    public function testSchemaAndPhpRejectStrictMutationCorpus(): void
    {
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json');
        $base = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $mutations = [];
        $extra = $base;
        $extra['risk_budget'] = 0.01;
        $mutations['setup-owned risk'] = $extra;
        $leverage = $base;
        $leverage['leverage_cap'] = 12;
        $mutations['setup-owned leverage cap'] = $leverage;
        $status = $base;
        $status['status'] = 'active';
        $mutations['premature activation'] = $status;
        $decision = $base;
        $decision['execution']['stop']['value'] = ['atr_k' => 1.5];
        $mutations['unresolved decision with value'] = $decision;
        $condition = $base;
        $condition['context']['trigger']['nodes'][0]['condition'] = 'typo_condition';
        $mutations['unknown condition'] = $condition;

        $jsonValidator = new JsonSchemaValidator();
        foreach ($mutations as $label => $mutation) {
            $object = json_decode(json_encode($mutation, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($jsonValidator->validate($object, $schema)->isValid(), $label);
            try {
                (new SetupContractValidator())->validate($mutation);
                self::fail('PHP validator accepted mutation: ' . $label);
            } catch (SetupContractException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSetupContractsDoNotOwnModeOrExchangeRiskFields(): void
    {
        foreach (glob($this->root . '/*/1.0.0.yaml') ?: [] as $path) {
            $document = $this->yaml($path);
            foreach (['risk', 'risk_budget', 'leverage', 'leverage_cap', 'exchange_fees'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $document, $path);
            }
        }
    }

    public function testPhpAndSchemaRejectUnsupportedTimeframeAndMistypedConditionParameter(): void
    {
        $base = $this->yaml($this->root . '/micro_scalping.momentum_ofi.long/1.0.0.yaml');
        $badTimeframe = $base;
        $badTimeframe['context']['trigger']['nodes'][1]['timeframe'] = '99m';
        $badParameter = $base;
        $badParameter['context']['trigger']['nodes'][1]['parameters'] = ['max_spred_bps' => 8];
        $badParameterType = $base;
        $badParameterType['context']['trigger']['nodes'][1]['parameters'] = ['max_spread_bps' => 'eight'];

        foreach (['unsupported timeframe' => $badTimeframe, 'mistyped spread parameter' => $badParameter, 'mistyped spread parameter type' => $badParameterType] as $label => $mutation) {
            $this->assertPhpAndSchemaReject($mutation, $label);
        }
    }

    public function testCompilerPreservesNestedBooleanEvidenceBranches(): void
    {
        $scalping = (new SetupCompiler())->compile(
            (new SetupContractLoader($this->root))->load('scalping.trend_continuation.long', '1.0.0'),
        )->ast;

        self::assertSame('all_of', $scalping['trigger']['op']);
        self::assertSame('any_of', $scalping['trigger']['nodes'][4]['op']);
        self::assertSame(
            ['macd_hist_gt_eps', 'macd_hist_slope_pos', 'macd_line_above_signal'],
            array_column($scalping['trigger']['nodes'][4]['nodes'], 'condition'),
        );
        self::assertSame('any_of', $scalping['confirmations']['nodes'][0]['nodes'][0]['op']);

        $crash = (new SetupCompiler())->compile(
            (new SetupContractLoader($this->root))->load('crash_short', '1.0.0'),
        )->ast;
        self::assertSame('any_of', $crash['confirmations']['op']);
        self::assertCount(2, $crash['confirmations']['nodes']);
        self::assertSame('crash_short_entry_1m', $crash['confirmations']['nodes'][1]['nodes'][1]['condition']);
    }

    public function testSourceOriginRangesCoverEveryRuleProvenanceRange(): void
    {
        $expected = [
            'day_trading.trend_continuation.long' => '7-12,84-88,238-349',
            'day_trading.trend_continuation.short' => '7-12,84-88,238-349',
            'scalping.trend_continuation.long' => '6-14,216-356',
            'scalping.pullback.long' => '6-14,157-161,216-356',
            'scalping.trend_momentum.short' => '6-14,216-356',
            'micro_scalping.momentum_ofi.long' => '5-19,87-115',
            'micro_scalping.momentum_ofi.short' => '5-19,95-125',
            'crash_short' => '5-16,136-137,164-167,169-305',
        ];
        $loader = new SetupContractLoader($this->root);
        foreach ($expected as $id => $range) {
            self::assertSame($range, $loader->load($id, '1.0.0')->toArray()['source_origin']['line_range']);
        }
    }

    public function testSchemaAndPhpRejectWhitespaceOnlyDirectionParameter(): void
    {
        $document = $this->yaml($this->root . '/day_trading.trend_continuation.short/1.0.0.yaml');
        $document['filters'][2]['condition'] = 'pullback_confirmed';
        $document['filters'][2]['parameters'] = ['direction' => '   '];

        $this->assertPhpAndSchemaReject($document, 'whitespace-only direction');
    }

    public function testRegularContractsPreserveExactOneHourAdxConditionIdentity(): void
    {
        $loader = new SetupContractLoader($this->root);
        foreach (['day_trading.trend_continuation.long', 'day_trading.trend_continuation.short'] as $id) {
            $conditions = array_column($loader->load($id, '1.0.0')->toArray()['filters'], 'condition');
            self::assertContains('adx_min_for_trend_1h', $conditions, $id);
            self::assertNotContains('adx_min_for_trend', $conditions, $id);
        }
    }

    public function testCrashPreservesLeverageBoundsAsUnresolvedExternalSafetyDependency(): void
    {
        $contract = (new SetupContractLoader($this->root))->load('crash_short', '1.0.0');
        $document = $contract->toArray();

        self::assertContains('lev_bounds', array_column($document['filters'], 'condition'));
        self::assertSame([
            'dependency_id' => 'lev_bounds',
            'state' => 'unresolved',
            'owner' => 'mode_or_exchange',
            'source' => 'src/MtfValidator/config/validations.crash.yaml:164-167,247-249',
            'justification' => 'Legacy bounds cannot become setup-owned leverage policy; authoritative modern limits are unresolved.',
            'failure_policy' => 'reject',
        ], $document['data_condition_contract']['external_dependencies'][0]);
        self::assertContains('data_condition_contract.external_dependencies.0', $contract->unresolvedPaths());
        self::assertFalse((new SetupCompiler())->compile($contract)->publishable);
    }

    public function testPhpAndSchemaRejectDuplicateExternalDependencies(): void
    {
        $document = $this->yaml($this->root . '/crash_short/1.0.0.yaml');
        $document['data_condition_contract']['external_dependencies'][] =
            $document['data_condition_contract']['external_dependencies'][0];

        $this->assertPhpAndSchemaReject($document, 'duplicate external dependency');
    }

    public function testConditionCatalogHashIsSpecializedAndVerifiedDuringCompilation(): void
    {
        $catalog = new ConditionCatalog(['rsi_lt_70', 'near_vwap']);
        $document = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $document['data_condition_contract']['condition_catalog_hash'] = [
            'state' => 'defined', 'value' => $catalog->stableHash(), 'unit' => 'sha256',
            'source' => 'test catalog', 'justification' => 'Exact test catalog hash.',
        ];
        $snapshot = (new SetupCompiler())->compile(SetupContract::fromDocument($document), $catalog);
        self::assertSame($catalog->stableHash(), $snapshot->conditionCatalogHash);

        $mismatch = new ConditionCatalog(['rsi_lt_70']);
        try {
            (new SetupCompiler())->compile(SetupContract::fromDocument($document), $mismatch);
            self::fail('Compiler accepted mismatched condition catalog hash.');
        } catch (SetupContractException $exception) {
            self::assertStringContainsString('Condition catalog hash mismatch', $exception->getMessage());
        }

        foreach ([['unit' => 'md5'], ['value' => ['not-a-string']]] as $mutation) {
            $invalid = $document;
            $invalid['data_condition_contract']['condition_catalog_hash'] = array_replace(
                $invalid['data_condition_contract']['condition_catalog_hash'],
                $mutation,
            );
            $this->assertPhpAndSchemaReject($invalid, 'invalid specialized catalog hash');
        }
    }

    public function testCompilerCanonicalizesExecutionRecursivelyForStableSerializedSnapshot(): void
    {
        $document = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $reordered = $document;
        $execution = $reordered['execution'];
        $reordered['execution'] = array_reverse($execution, true);
        $reordered['execution']['entry_zone'] = array_reverse($reordered['execution']['entry_zone'], true);

        $compiler = new SetupCompiler();
        $first = $compiler->compile(SetupContract::fromDocument($document))->toArray();
        $second = $compiler->compile(SetupContract::fromDocument($reordered))->toArray();
        self::assertSame($first['configHash'], $second['configHash']);
        self::assertSame($first, $second);
    }

    public function testPhpAndSchemaRejectDuplicateProvenancePathsBeforeCompilation(): void
    {
        $document = $this->yaml($this->root . '/crash_short/1.0.0.yaml');
        $duplicate = $document['provenance'][0];
        $duplicate['justification'] = 'Different row content with the same path must still reject.';
        $document['provenance'][] = $duplicate;

        $this->assertPhpAndSchemaReject($document, 'duplicate provenance path');
    }

    public function testPhpAndSchemaRejectInvalidSourceLineRangeGrammar(): void
    {
        $document = $this->yaml($this->root . '/crash_short/1.0.0.yaml');
        $document['source_origin']['line_range'] = '5-16, bad';

        $this->assertPhpAndSchemaReject($document, 'invalid source line range');
    }

    public function testPhpRejectsNonFiniteNumericConditionParameterWithDomainException(): void
    {
        $document = $this->yaml($this->root . '/micro_scalping.momentum_ofi.long/1.0.0.yaml');
        $document['context']['trigger']['nodes'][1]['parameters']['max_spread_bps'] = INF;

        $this->expectException(SetupContractException::class);
        $this->expectExceptionMessage('finite');
        (new SetupContractValidator())->validate($document);
    }

    public function testPhpAndSchemaRejectDuplicateStringListItems(): void
    {
        $mutations = [];
        $required = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $required['data_condition_contract']['required_data'][] = $required['data_condition_contract']['required_data'][0];
        $mutations['required_data'] = $required;
        $missing = $this->yaml($this->root . '/micro_scalping.momentum_ofi.long/1.0.0.yaml');
        $missing['data_condition_contract']['missing_conditions'][] = $missing['data_condition_contract']['missing_conditions'][0];
        $mutations['missing_conditions'] = $missing;
        $defects = $this->yaml($this->root . '/crash_short/1.0.0.yaml');
        $defects['known_defects'][] = $defects['known_defects'][0];
        $mutations['known_defects'] = $defects;

        foreach ($mutations as $label => $mutation) {
            $this->assertPhpAndSchemaReject($mutation, 'duplicate ' . $label);
        }
    }

    /** @param array<string, mixed> $mutation */
    private function assertPhpAndSchemaReject(array $mutation, string $label): void
    {
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json');
        $object = json_decode(json_encode($mutation, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertFalse((new JsonSchemaValidator())->validate($object, $schema)->isValid(), $label . ' JSON Schema');
        try {
            (new SetupContractValidator())->validate($mutation);
            self::fail('PHP validator accepted ' . $label);
        } catch (SetupContractException) {
            self::addToAssertionCount(1);
        }
    }

    /** @return array<string, mixed> */
    private function yaml(string $path): array
    {
        $document = Yaml::parseFile($path);
        self::assertIsArray($document);

        return $document;
    }

    private function jsonObject(string $path): object
    {
        $object = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($object);

        return $object;
    }
}
