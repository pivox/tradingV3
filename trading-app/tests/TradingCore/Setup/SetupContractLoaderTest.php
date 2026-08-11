<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Setup;

use App\Indicator\Context\CanonicalPullbackAgeCalculator;
use App\TradingCore\Setup\Exception\SetupContractException;
use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
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

    public function testStableHashTracksCanonicalSetupContentRatherThanMappingOrder(): void
    {
        $contract = (new SetupContractLoader($this->root))->load('scalping.pullback.long', '1.0.0');
        $document = $contract->toArray();
        $reordered = $document;
        krsort($reordered, SORT_STRING);

        self::assertSame($contract->stableHash(), SetupContract::fromDocument($reordered)->stableHash());

        $changed = $document;
        $changed['thesis'] .= ' Semantic change.';
        self::assertNotSame($contract->stableHash(), SetupContract::fromDocument($changed)->stableHash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract->stableHash());
    }

    public function testPublishesExecutableDayTradingLongShadowSetup(): void
    {
        $loader = new SetupContractLoader($this->root);
        $contract = $loader->load('day_trading.trend_continuation.long', '1.1.0');
        $compiled = (new SetupCompiler())->compile($contract);
        $execution = $compiled->ast['execution'];

        self::assertSame('shadow', $contract->status);
        self::assertTrue($contract->isExecutable());
        self::assertTrue($compiled->publishable);
        self::assertSame([], $contract->unresolvedPaths());
        self::assertSame(['day_trading' => '1.1.0'], $compiled->modeVersions);
        self::assertSame('15m', $execution['execution_timeframe']['value']);
        self::assertSame(['5m', '1m'], $execution['mandatory_confirmations']['value']);
        self::assertSame(0.30, $execution['entry_zone']['value']['atr_multiplier']);
        self::assertSame(2.0, $execution['targets']['value'][0]['risk_multiple']);
        self::assertSame(1.3, $execution['minimum_net_r']['value']);
        self::assertSame('limit', $execution['order_policy']['value']['type']);
        self::assertFalse($execution['order_policy']['value']['market_fallback']);
        self::assertSame('blocked', $loader->load('day_trading.trend_continuation.short', '1.0.0')->status);
    }

    public function testDayTradingLongShadowHasPhpAndSchemaParity(): void
    {
        $document = $this->yaml($this->root . '/day_trading.trend_continuation.long/1.1.0.yaml');
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json');
        $validator = new JsonSchemaValidator();
        $object = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($validator->validate($object, $schema)->isValid());

        $mutations = [];
        $mutations['market fallback'] = $document;
        $mutations['market fallback']['execution']['order_policy']['value']['market_fallback'] = true;
        $mutations['execution descent'] = $document;
        $mutations['execution descent']['execution']['execution_timeframe']['value'] = '1m';
        $mutations['missing confirmation'] = $document;
        $mutations['missing confirmation']['execution']['mandatory_confirmations']['value'] = ['5m'];

        foreach ($mutations as $label => $mutation) {
            try {
                (new SetupContractValidator())->validate($mutation);
                self::fail('PHP accepted mutation: ' . $label);
            } catch (SetupContractException) {
                self::addToAssertionCount(1);
            }
            $mutationObject = json_decode(json_encode($mutation, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($validator->validate($mutationObject, $schema)->isValid(), 'schema accepted mutation: ' . $label);
        }
    }

    public function testDayTradingLongShadowFixtureFreezesPassAndFailScenarios(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Fixtures/TradingCore/Setup/day-trading-long-1.1.0-scenarios.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('day_trading.trend_continuation.long', $fixture['setup_id']);
        self::assertSame('1.1.0', $fixture['setup_version']);
        self::assertSame([
            'valid_long' => 'pass',
            'failed_condition' => 'no_trade',
            'missing_1m' => 'no_trade',
            'stale_5m' => 'no_trade',
        ], array_column($fixture['scenarios'], 'expectation', 'id'));
        foreach ($fixture['scenarios'] as $scenario) {
            self::assertNotSame([], $scenario['evidence']);
        }
    }

    public function testPublishesThreeIndependentExecutableScalpingShadowSetups(): void
    {
        $loader = new SetupContractLoader($this->root);
        $expectedSides = [
            'scalping.trend_continuation.long' => 'long',
            'scalping.pullback.long' => 'long',
            'scalping.trend_momentum.short' => 'short',
        ];

        foreach ($expectedSides as $setupId => $side) {
            $contract = $loader->load($setupId, '1.1.0');
            $compiled = (new SetupCompiler())->compile($contract);
            $execution = $compiled->ast['execution'];

            self::assertSame('shadow', $contract->status, $setupId);
            self::assertTrue($contract->isExecutable(), $setupId);
            self::assertTrue($compiled->publishable, $setupId);
            self::assertSame([], $contract->unresolvedPaths(), $setupId);
            self::assertSame($side, $contract->side, $setupId);
            self::assertSame($side, $compiled->ast['side'], $setupId);
            self::assertSame(['scalping' => '1.1.0'], $compiled->modeVersions, $setupId);
            self::assertSame('5m', $execution['execution_timeframe']['value'], $setupId);
            self::assertSame(['1m'], $execution['mandatory_confirmations']['value'], $setupId);
            self::assertSame(0.22, $execution['entry_zone']['value']['atr_multiplier'], $setupId);
            self::assertSame(150, $execution['entry_zone']['value']['ttl_seconds'], $setupId);
            self::assertSame(1.5, $execution['stop']['value']['atr_multiplier'], $setupId);
            self::assertSame(1.8, $execution['targets']['value'][0]['risk_multiple'], $setupId);
            self::assertSame(1.3, $execution['minimum_net_r']['value'], $setupId);
            self::assertSame(45, $execution['order_policy']['value']['ttl_seconds'], $setupId);
            self::assertSame(75, $execution['order_policy']['value']['cancel_after_seconds'], $setupId);
            self::assertFalse($execution['order_policy']['value']['market_fallback'], $setupId);
            self::assertSame('PT5M', $contract->toArray()['validity_window']['value'], $setupId);
            self::assertSame([], $contract->toArray()['known_defects'], $setupId);
            self::assertSame([
                'ohlcv_1h', 'ohlcv_15m', 'ohlcv_5m', 'ohlcv_1m', 'ema', 'macd', 'rsi', 'atr',
                'vwap', 'volume_ratio', 'order_book', 'fee_schedule', 'funding_schedule',
            ], $contract->toArray()['data_condition_contract']['required_data'], $setupId);
        }
    }

    public function testScalpingShadowVersionsCopyEachLegacyRuleTreeWithoutCrossSetupRescue(): void
    {
        $loader = new SetupContractLoader($this->root);
        $conditionCounts = [];

        foreach ([
            'scalping.trend_continuation.long',
            'scalping.pullback.long',
            'scalping.trend_momentum.short',
        ] as $setupId) {
            $legacy = $loader->load($setupId, '1.0.0')->toArray();
            $shadow = $loader->load($setupId, '1.1.0')->toArray();
            foreach (['context', 'filters', 'no_trade_rules'] as $tree) {
                self::assertSame($legacy[$tree], $shadow[$tree], $setupId . ' ' . $tree);
            }
            $conditionCounts[$setupId] = substr_count(
                json_encode([$shadow['context'], $shadow['filters'], $shadow['no_trade_rules']], JSON_THROW_ON_ERROR),
                'pullback_confirmed',
            );
        }

        self::assertSame([
            'scalping.trend_continuation.long' => 0,
            'scalping.pullback.long' => 1,
            'scalping.trend_momentum.short' => 0,
        ], $conditionCounts);
    }

    public function testScalpingShadowHasPhpAndSchemaParityForFrozenExecutionMutations(): void
    {
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json');
        $jsonValidator = new JsonSchemaValidator();

        foreach ([
            'scalping.trend_continuation.long',
            'scalping.pullback.long',
            'scalping.trend_momentum.short',
        ] as $setupId) {
            $document = $this->yaml($this->root . '/' . $setupId . '/1.1.0.yaml');
            $object = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($jsonValidator->validate($object, $schema)->isValid(), $setupId);
            (new SetupContractValidator())->validate($document);

            $numericEquivalent = $document;
            $numericEquivalent['execution']['entry_zone']['value']['asymmetry_rate'] = 0;
            $numericEquivalent['execution']['order_policy']['value']['maximum_spread_bps'] = 6;
            $numericEquivalent['execution']['order_policy']['value']['maximum_slippage_bps'] = 8;
            (new SetupContractValidator())->validate($numericEquivalent);
            $numericObject = json_decode(json_encode($numericEquivalent, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($jsonValidator->validate($numericObject, $schema)->isValid(), $setupId . ' numeric parity');

            $mutations = [];
            $mutations['execution timeframe'] = $document;
            $mutations['execution timeframe']['execution']['execution_timeframe']['value'] = '1m';
            $mutations['confirmation'] = $document;
            $mutations['confirmation']['execution']['mandatory_confirmations']['value'] = [];
            $mutations['entry zone'] = $document;
            $mutations['entry zone']['execution']['entry_zone']['value']['atr_multiplier'] = 0.23;
            $mutations['stop'] = $document;
            $mutations['stop']['execution']['stop']['value']['pivot_id'] = 's1';
            $mutations['target'] = $document;
            $mutations['target']['execution']['targets']['value'][0]['risk_multiple'] = 2.0;
            $mutations['minimum net R'] = $document;
            $mutations['minimum net R']['execution']['minimum_net_r']['value'] = 1.2;
            $mutations['time stop'] = $document;
            $mutations['time stop']['execution']['time_stop']['value'] = 'PT3H';
            $mutations['order ttl'] = $document;
            $mutations['order ttl']['execution']['order_policy']['value']['ttl_seconds'] = 46;
            $mutations['market fallback'] = $document;
            $mutations['market fallback']['execution']['order_policy']['value']['market_fallback'] = true;
            $mutations['validity'] = $document;
            $mutations['validity']['validity_window']['value'] = 'PT15M';
            $mutations['defect rescue'] = $document;
            $mutations['defect rescue']['known_defects'] = ['legacy selector defect'];
            $mutations['compatibility version'] = $document;
            $mutations['compatibility version']['compatible_modes'][0]['mode_version'] = '1.0.0';
            $mutations['catalog hash'] = $document;
            $mutations['catalog hash']['data_condition_contract']['condition_catalog_hash']['value'] = str_repeat('0', 64);
            $mutations['source pin'] = $document;
            $mutations['source pin']['source_origin']['commit'] = str_repeat('a', 40);

            foreach ($mutations as $label => $mutation) {
                $this->assertPhpAndSchemaReject($mutation, $setupId . ' ' . $label);
            }
        }
    }

    public function testScalpingShadowFixtureFreezesIndependentPassAndNoRescueScenarios(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Fixtures/TradingCore/Setup/scalping-1.1.0-scenarios.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('scalping-shadow-scenarios.v1', $fixture['schema_version']);
        self::assertSame([
            'continuation_long_pass' => 'pass',
            'pullback_long_pass' => 'pass',
            'short_momentum_pass' => 'pass',
            'scenario_a_cannot_rescue_pullback' => 'no_trade',
            'scenario_b_cannot_rescue_continuation' => 'no_trade',
            'missing_1m' => 'no_trade',
        ], array_column($fixture['scenarios'], 'expectation', 'id'));
        foreach ($fixture['scenarios'] as $scenario) {
            self::assertContains($scenario['setup_id'], [
                'scalping.trend_continuation.long',
                'scalping.pullback.long',
                'scalping.trend_momentum.short',
            ]);
            self::assertNotSame([], $scenario['evidence']);
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

    public function testCrashDecisionUsesANewExactVersionWithoutCreatingANinthIdentity(): void
    {
        $loader = new SetupContractLoader($this->root);
        $legacy = $loader->load('crash_short', '1.0.0')->toArray();
        $decided = $loader->load('crash_short', '1.1.0');
        $document = $decided->toArray();

        self::assertSame('unresolved', $legacy['mode_compatibility']['state']);
        self::assertSame('1.1.0', $decided->setupVersion);
        self::assertSame('blocked', $decided->status);
        self::assertSame([], $document['compatible_modes']);
        self::assertSame('distinct_operational_envelope', $document['mode_compatibility']['state']);
        self::assertSame('#310', $document['mode_compatibility']['issue']);
        self::assertArrayNotHasKey('source_origin', $document);
        self::assertSame(
            [
                'src/MtfValidator/config/validations.crash.yaml',
                'config/app/trade_entry.crash.yaml',
            ],
            array_column($document['source_origins'], 'file'),
        );
        self::assertFalse($decided->isExecutable());
        self::assertFalse((new SetupCompiler())->compile($decided)->publishable);
        self::assertSame($document['source_origins'], (new SetupCompiler())->compile($decided)->sourceOrigins);

        $ids = array_map('basename', glob($this->root . '/*', GLOB_ONLYDIR) ?: []);
        self::assertCount(8, array_unique($ids));
        self::assertNotContains('crash', $ids);
        self::assertNotContains('crash_pullback', $ids);
    }

    public function testCrashDecisionRemovesRedundantPullbackOrFromCanonicalAst(): void
    {
        $snapshot = (new SetupCompiler())->compile(
            (new SetupContractLoader($this->root))->load('crash_short', '1.1.0'),
        );
        $confirmations = $snapshot->ast['confirmations'];

        self::assertSame('any_of', $confirmations['op']);
        self::assertCount(2, $confirmations['nodes']);
        self::assertSame(
            'crash_short_pattern_5m',
            $confirmations['nodes'][0]['condition'],
        );
        self::assertStringContainsString('execution_variant=5m_default', $confirmations['nodes'][0]['provenance']);
        self::assertStringContainsString('execution_variant=1m_extreme', $confirmations['nodes'][1]['provenance']);
        self::assertStringNotContainsString(
            'crash_short_entry_1m',
            json_encode($snapshot->ast, JSON_THROW_ON_ERROR),
        );
        self::assertSame(1, substr_count(json_encode($snapshot->ast, JSON_THROW_ON_ERROR), 'crash_context_ok'));
        $catalog = $this->catalog();
        self::assertSame(['1h'], $catalog->definition('crash_context_ok')->timeframes);
        self::assertSame('blocked', $catalog->definition('crash_short_entry_1m')->status);
    }

    public function testCrashDecisionIsCompleteButFailsClosedOnUnknownExecutionAndRiskInputs(): void
    {
        $contract = (new SetupContractLoader($this->root))->load('crash_short', '1.1.0');
        $document = $contract->toArray();

        foreach (['entry_zone', 'stop', 'targets', 'minimum_net_r', 'invalidation', 'time_stop', 'cost_contract', 'order_policy', 'risk_boundary'] as $key) {
            self::assertSame('unresolved', $document['execution'][$key]['state'], $key);
            self::assertNull($document['execution'][$key]['value'], $key);
        }
        self::assertSame('reject', $document['execution']['cost_contract']['unknown_policy']);
        self::assertSame('reject', $document['execution']['order_policy']['unknown_policy']);
        self::assertSame('future_compatible_envelope', $document['execution']['risk_boundary']['unit']);
        self::assertSame('unresolved', $document['validity_window']['state']);
        self::assertContains('execution.cost_contract', $contract->unresolvedPaths());
        self::assertContains('execution.risk_boundary', $contract->unresolvedPaths());

        $blockers = implode(' ', array_merge(
            $document['governance']['shadow'],
            $document['governance']['paper'],
            $document['governance']['promotion'],
        ));
        foreach (['#303', '#304', '#132', '#191'] as $issue) {
            self::assertStringContainsString($issue, $blockers);
        }
        self::assertStringNotContainsString('BitMart fallback', $blockers);
    }

    public function testCrashDecisionProvenanceInventoriesValidationAndTradeEntrySources(): void
    {
        $document = (new SetupContractLoader($this->root))->load('crash_short', '1.1.0')->toArray();
        $sources = array_column($document['provenance'], 'source', 'path');

        foreach (['mode_compatibility', 'context.regime', 'context.context', 'context.trigger', 'context.confirmations', 'filters'] as $path) {
            self::assertStringContainsString('validations.crash.yaml:', $sources[$path]);
        }
        foreach (['execution.entry_zone', 'execution.stop', 'execution.targets', 'execution.minimum_net_r', 'execution.invalidation', 'execution.time_stop', 'execution.cost_contract', 'execution.order_policy', 'execution.risk_boundary', 'validity_window'] as $path) {
            self::assertStringContainsString('trade_entry.crash.yaml:', $sources[$path]);
        }
        self::assertStringContainsString('redundant', $sources['legacy.retest_variant']);
    }

    public function testCrashDecisionFixturesStatePassFailOrBlockWithoutRuntimeClaims(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Fixtures/TradingCore/Setup/crash-short-1.1.0-scenarios.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($fixture);
        self::assertSame('crash_short', $fixture['setup_id']);
        self::assertSame('1.1.0', $fixture['setup_version']);
        self::assertFalse($fixture['runtime_executable']);
        self::assertSame(
            [
                'crash' => 'pass',
                'false_crash' => 'fail',
                'terminal_wick' => 'fail',
                'valid_retest' => 'block',
                'invalid_retest' => 'fail',
            ],
            array_column($fixture['scenarios'], 'expectation', 'id'),
        );
        foreach ($fixture['scenarios'] as $scenario) {
            self::assertNotSame('', trim($scenario['rationale']));
            self::assertNotSame([], $scenario['evidence']);
        }
    }

    public function testCrashDecisionHasOpisAndPhpParityAndRejectsVersionDrift(): void
    {
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json');
        $document = $this->yaml($this->root . '/crash_short/1.1.0.yaml');
        $object = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        self::assertTrue((new JsonSchemaValidator())->validate($object, $schema)->isValid());
        (new SetupContractValidator())->validate($document);

        $wrongIdentity = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $wrongIdentity['setup_version'] = '1.1.0';
        $this->assertPhpAndSchemaReject($wrongIdentity, '1.1.0 is crash_short-only');

        $costFallback = $document;
        $costFallback['execution']['cost_contract']['unknown_policy'] = 'zero';
        $this->assertPhpAndSchemaReject($costFallback, 'unknown crash costs cannot resolve to zero');

        $legacyWithDecisionFields = $this->yaml($this->root . '/crash_short/1.0.0.yaml');
        $legacyWithDecisionFields['execution']['order_policy'] = $document['execution']['order_policy'];
        $legacyWithDecisionFields['execution']['risk_boundary'] = $document['execution']['risk_boundary'];
        $this->assertPhpAndSchemaReject($legacyWithDecisionFields, '1.1.0 decision fields on crash_short 1.0.0');

        $missingTradeEntryOrigin = $document;
        array_pop($missingTradeEntryOrigin['source_origins']);
        $this->assertPhpAndSchemaReject($missingTradeEntryOrigin, 'missing trade entry source origin');

        $repinnedTradeEntryOrigin = $document;
        $repinnedTradeEntryOrigin['source_origins'][1]['commit'] = str_repeat('a', 40);
        $this->assertPhpAndSchemaReject($repinnedTradeEntryOrigin, 'repinned trade entry source origin');
    }

    public function testCrashFailClosedDecisionsCannotBecomeDefined(): void
    {
        $document = $this->yaml($this->root . '/crash_short/1.1.0.yaml');

        foreach (['cost_contract', 'order_policy', 'risk_boundary'] as $decision) {
            $defined = $document;
            $defined['execution'][$decision]['state'] = 'defined';
            $defined['execution'][$decision]['value'] = ['policy' => 'invented'];

            $this->assertPhpAndSchemaReject($defined, $decision . ' defined/non-null attempt');
        }
    }

    public function testSourceOriginsPinExactCurrentContentHashes(): void
    {
        $expected = [
            'validations.regular.yaml' => ['e15ec9ea51330c83b2d0f14791a7985fc06793ee925c32eb4d5d962b3b2e1a13', '719f70cd65b4e68d0ad11e0046af98ab5839ea4e'],
            'validations.scalper.yaml' => ['5bf86ce415079ee896a98d2c91e987d11db975c986500862b0cff82440c590a2', '6c42d14d20798f6fee9d55b306ccaa0539af5e79'],
            'validations.scalper_micro.yaml' => ['47969bd5055b28ba5871b0b22e503482730a368c56fad3d0963aaad3808808e2', '75a4cef8852f99d6e8202422fdd0531cdfce60bb'],
            'validations.crash.yaml' => ['5dd5cbf03cdbcb804cd664e47c0dce4007438bbce973af027a05e7155b2c10e2', 'd1d9a174960660e88f84c54850ef61181d39a880'],
            'trade_entry.crash.yaml' => ['722bd2ee013a24ae86ffae2aa846437db7a51898ef8de4a0cd58e693a8ffb90f', '6ff8ab88e1bb9465f92f39424ae64305ca20ee0d'],
        ];
        $expectedDecisionRanges = [
            'src/MtfValidator/config/validations.crash.yaml' => '5-16,136-137,164-167,169-305',
            'config/app/trade_entry.crash.yaml' => '7-12,19-205',
            'src/MtfValidator/config/validations.regular.yaml' => '7-12,84-88,238-349',
        ];
        $expectedScalpingDecisionRanges = [
            'scalping.trend_continuation.long' => '6-14,216-356',
            'scalping.pullback.long' => '6-14,157-161,216-356',
            'scalping.trend_momentum.short' => '6-14,216-356',
        ];

        foreach (glob($this->root . '/*/*.yaml') ?: [] as $path) {
            $document = Yaml::parseFile($path);
            self::assertIsArray($document);
            $origins = isset($document['source_origins']) ? $document['source_origins'] : [$document['source_origin']];
            self::assertNotSame([], $origins, $path);
            self::assertCount(count($origins), array_unique(array_column($origins, 'file')), $path);
            foreach ($origins as $origin) {
                $basename = basename($origin['file']);
                self::assertSame($expected[$basename][0], $origin['content_sha256'], $path);
                self::assertSame($expected[$basename][1], $origin['commit'], $path);
                $sourcePath = dirname(__DIR__, 3) . '/' . $origin['file'];
                self::assertSame($origin['content_sha256'], hash_file('sha256', $sourcePath), $sourcePath);
                self::assertMatchesRegularExpression('/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/', $origin['line_range']);
                if (($document['setup_version'] ?? null) === '1.1.0') {
                    $expectedRange = $expectedScalpingDecisionRanges[$document['setup_id']]
                        ?? $expectedDecisionRanges[$origin['file']];
                    self::assertSame($expectedRange, $origin['line_range']);
                }
            }
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
        $document = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $document['context']['trigger']['nodes'][0]['condition'] = 'invented_condition';

        $this->expectException(SetupContractException::class);
        $this->expectExceptionMessage('Unknown condition "invented_condition"');
        (new SetupContractValidator())->validate($document);
    }

    public function testSetupSchemaConditionEnumMatchesPublicCatalogSurface(): void
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($schema);
        $schemaConditionIds = $schema['$defs']['condition']['enum'] ?? null;
        self::assertIsArray($schemaConditionIds);
        sort($schemaConditionIds, SORT_STRING);

        $internalCompositeDependencies = [
            'close_above_ma_9', 'close_above_vwap', 'close_below_ma_9',
            'ema_20_gt_50', 'ema_20_slope_pos', 'ma9_cross_up_ma21',
        ];
        $publicConditionIds = array_values(array_diff($this->catalog()->conditionIds(), $internalCompositeDependencies));

        self::assertSame($publicConditionIds, $schemaConditionIds);
    }

    public function testSetupSchemaParameterKeysMatchCanonicalCatalog(): void
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($schema);
        $schemaParameterKeys = array_keys($schema['$defs']['parameters']['properties'] ?? []);
        sort($schemaParameterKeys, SORT_STRING);
        $catalogParameterKeys = [];
        $catalog = $this->catalog();
        foreach ($catalog->conditionIds() as $conditionId) {
            $catalogParameterKeys = array_merge($catalogParameterKeys, array_keys($catalog->definition($conditionId)->parameters));
        }
        $catalogParameterKeys = array_values(array_unique($catalogParameterKeys));
        sort($catalogParameterKeys, SORT_STRING);

        self::assertSame($catalogParameterKeys, $schemaParameterKeys);
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
        self::assertSame([$contract->toArray()['source_origin']], $first->sourceOrigins);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->configHash);
        self::assertSame($this->catalog()->stableHash(), $first->conditionCatalogHash);
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

    public function testCrashRsiFloorIsAMandatoryFilterNotAnInvertedNoTradeMatch(): void
    {
        $loader = new SetupContractLoader($this->root);
        foreach (['1.0.0', '1.1.0'] as $version) {
            $document = $loader->load('crash_short', $version)->toArray();

            self::assertSame([], $document['no_trade_rules']);
            self::assertContains('rsi_5m_gt_floor', array_column($document['filters'], 'condition'));
        }
    }

    public function testCanonicalPullbackIsExecutableWhileUnsupportedTimeframesRemainFailClosed(): void
    {
        $catalog = $this->catalog();
        self::assertSame('executable', $catalog->definition('pullback_confirmed')->status);
        self::assertStringContainsString(CanonicalPullbackAgeCalculator::class, $catalog->definition('pullback_confirmed')->provenance);
        self::assertSame(['1h', '4h'], $catalog->definition('price_regime_ok_long')->timeframes);
        self::assertSame(['1h', '4h'], $catalog->definition('price_regime_ok_short')->timeframes);

        $short = (new SetupContractLoader($this->root))->load('scalping.trend_momentum.short', '1.0.0')->toArray();
        self::assertNotContains('price_regime_ok_short', array_column($short['context']['context']['nodes'], 'condition'));
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
        self::assertSame('crash_short_entry_1m', $crash['confirmations']['nodes'][1]['condition']);
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
        $catalog = $this->catalog();
        $document = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $document['data_condition_contract']['condition_catalog_hash'] = [
            'state' => 'defined', 'value' => $catalog->stableHash(), 'unit' => 'sha256',
            'source' => 'test catalog', 'justification' => 'Exact test catalog hash.',
        ];
        $snapshot = (new SetupCompiler())->compile(SetupContract::fromDocument($document), $catalog);
        self::assertSame($catalog->stableHash(), $snapshot->conditionCatalogHash);

        $mismatchedDocument = $document;
        $mismatchedDocument['data_condition_contract']['condition_catalog_hash']['value'] = str_repeat('0', 64);
        try {
            (new SetupCompiler())->compile(SetupContract::fromDocument($mismatchedDocument), $catalog);
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

    public function testCompilerExposesCompleteCanonicalEffectiveSetupPayload(): void
    {
        $contract = (new SetupContractLoader($this->root))->load('scalping.pullback.long', '1.0.0');
        $document = $contract->toArray();
        $snapshot = (new SetupCompiler())->compile($contract);
        $payload = $snapshot->effectivePayload();

        self::assertSame('compiled-setup.v1', $payload['schema_version']);
        self::assertSame($document['hypothesis'], $payload['hypothesis']);
        self::assertEquals($document['context']['confirmations'], $payload['ast']['confirmations']);
        self::assertEquals(['op' => 'all_of', 'nodes' => $document['filters']], $payload['ast']['filters']);
        self::assertEquals(['op' => 'all_of', 'nodes' => $document['no_trade_rules']], $payload['ast']['no_trade_rules']);
        self::assertEquals($document['execution'], $payload['ast']['execution']);
        self::assertEquals($document['missing_data_policy'], $payload['missing_data_policy']);
        self::assertEquals($document['data_condition_contract'], $payload['data_condition_contract']);
        self::assertEquals([$document['source_origin']], $payload['source_origins']);
        self::assertSame($snapshot->provenanceByKey, $payload['contract_provenance']);
        self::assertSame($snapshot->configHash, $payload['contract_hash']);
        self::assertNotContains('condition_catalog_hash_unresolved', $payload['blockers']);
        self::assertFalse($payload['publishable']);
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

    public function testCompilerRejectsPartialConditionCatalogAcrossNestedAstBranches(): void
    {
        $contract = (new SetupContractLoader($this->root))->load('scalping.pullback.long', '1.0.0');

        $this->expectException(SetupContractException::class);
        $this->expectExceptionMessage('Supplied condition catalog is missing:');
        $this->expectExceptionMessage('pullback_confirmed');
        $document = Yaml::parseFile(dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml');
        self::assertIsArray($document);
        $document['conditions'] = array_values(array_filter(
            $document['conditions'],
            static fn (array $row): bool => ($row['id'] ?? null) === 'near_vwap',
        ));
        (new SetupCompiler())->compile($contract, (new ConditionCatalogLoader())->load($document));
    }

    public function testExternalSafetyDependencyIsExcludedFromConditionCatalogCoverage(): void
    {
        $contract = (new SetupContractLoader($this->root))->load('crash_short', '1.0.0');
        $snapshot = (new SetupCompiler())->compile($contract, $this->catalog());

        self::assertFalse($snapshot->publishable);
        self::assertContains('data_condition_contract.external_dependencies.0', $contract->unresolvedPaths());
    }

    public function testPhpAndSchemaRejectPathOutsideFrozenProvenanceCatalog(): void
    {
        $document = $this->yaml($this->root . '/scalping.pullback.long/1.0.0.yaml');
        $document['provenance'][0]['path'] = 'context.typo';

        $this->assertPhpAndSchemaReject($document, 'unknown provenance path');
    }

    public function testPhpAndSchemaExposeSameFrozenProvenancePathCatalog(): void
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/config/trading/schema/setup-contract.schema.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($schema);
        self::assertSame(
            SetupContractValidator::PROVENANCE_PATHS,
            $schema['$defs']['provenance']['properties']['path']['enum'],
        );
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

    private function catalog(): ConditionCatalog
    {
        return (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        );
    }
}
