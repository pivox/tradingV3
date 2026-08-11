<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Mode;

use App\TradingCore\Mode\Exception\ModeContractException;
use App\TradingCore\Mode\ModeContract;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Mode\ModeContractValidator;
use Opis\JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ModeContract::class)]
#[CoversClass(ModeContractLoader::class)]
#[CoversClass(ModeContractValidator::class)]
#[CoversClass(ModeContractException::class)]
final class ModeContractLoaderTest extends TestCase
{
    private string $contractRoot;

    protected function setUp(): void
    {
        $this->contractRoot = dirname(__DIR__, 3) . '/config/trading/mode_contract';
    }

    #[DataProvider('modernModes')]
    public function testLoadsOnlyFrozenModernModeAndVersion(string $modeId): void
    {
        $contract = (new ModeContractLoader($this->contractRoot))->load($modeId, '1.0.0');

        self::assertInstanceOf(ModeContract::class, $contract);
        self::assertSame($modeId, $contract->modeId);
        self::assertSame('1.0.0', $contract->modeVersion);
        self::assertSame('draft', $contract->lifecycleStatus);
        self::assertFalse($contract->isExecutable());
        self::assertNotEmpty($contract->unresolvedConstraints());
    }

    /** @return iterable<string, array{string}> */
    public static function modernModes(): iterable
    {
        yield 'day trading' => ['day_trading'];
        yield 'scalping' => ['scalping'];
        yield 'micro scalping' => ['micro_scalping'];
    }

    public function testLoadsExecutableDayTradingShadowVersionWithExactDecisions(): void
    {
        $contract = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.1.0');
        $document = $contract->toArray();

        self::assertSame('1.1.0', $contract->modeVersion);
        self::assertSame('shadow', $contract->lifecycleStatus);
        self::assertTrue($contract->isExecutable());
        self::assertSame([
            'maximum_duration' => 'PT8H',
            'daily_boundary_time' => '00:00:00',
            'daily_boundary_timezone' => 'UTC',
            'close_before_boundary' => true,
        ], $document['horizon']['value']);
        self::assertSame(['15m'], $contract->timeframeRoles()['execution']);
        self::assertSame(['5m', '1m'], $contract->timeframeRoles()['confirmations']);
        self::assertSame(5.0, $document['risk']['trade_budget']['value']);
        self::assertSame([
            'limit' => 4,
            'include_pending_entries' => true,
        ], $document['risk']['max_concurrent_positions']['value']);
        self::assertSame(100.0, $document['risk']['mode_exposure_cap']['value']);
        self::assertSame(2.0, $document['leverage']['value']);
        self::assertFalse($document['order_policy']['value']['market_fallback']);
    }

    public function testLoadsExecutableScalpingShadowVersionWithExactDecisions(): void
    {
        $contract = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.1.0');
        $document = $contract->toArray();

        self::assertSame('1.1.0', $contract->modeVersion);
        self::assertSame('shadow', $contract->lifecycleStatus);
        self::assertTrue($contract->isExecutable());
        self::assertSame([
            'maximum_duration' => 'PT2H',
            'daily_boundary_time' => '00:00:00',
            'daily_boundary_timezone' => 'UTC',
            'close_before_boundary' => true,
        ], $document['horizon']['value']);
        self::assertSame(['1h'], $contract->timeframeRoles()['regime']);
        self::assertSame(['15m'], $contract->timeframeRoles()['context']);
        self::assertSame(['5m'], $contract->timeframeRoles()['trigger']);
        self::assertSame(['5m'], $contract->timeframeRoles()['execution']);
        self::assertSame(['1m'], $contract->timeframeRoles()['confirmations']);
        self::assertSame('PT5M', $document['cadence']['evaluation']['value']);
        self::assertSame('PT5M', $document['cadence']['validity_window']['value']);
        self::assertSame(2.0, $document['risk']['trade_budget']['value']);
        self::assertSame([
            'percent_equity' => 6.0,
            'absolute_quote' => 40.0,
            'quote_currency' => 'USDT',
            'day_timezone' => 'UTC',
            'day_boundary_local' => '00:00:00',
            'include_unrealized_loss' => true,
        ], $document['risk']['daily_loss_cap']['value']);
        self::assertSame([
            'limit' => 3,
            'include_pending_entries' => true,
        ], $document['risk']['max_concurrent_positions']['value']);
        self::assertSame(75.0, $document['risk']['mode_exposure_cap']['value']);
        self::assertSame(3.0, $document['leverage']['value']);
        self::assertSame([
            'margin_mode' => 'isolated',
            'preferred_type' => 'limit',
            'market_fallback' => false,
        ], $document['order_policy']['value']);
    }

    public function testScalpingShadowMutationsFailInPhpAndJsonSchema(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.1.0')->toArray();
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/mode-contract.schema.json');
        $mutations = [];
        $mutations['horizon'] = $document;
        $mutations['horizon']['horizon']['value']['maximum_duration'] = 'PT3H';
        $mutations['horizon source'] = $document;
        $mutations['horizon source']['horizon']['source'] = 'synthetic-test';
        $mutations['missing confirmations'] = $document;
        unset($mutations['missing confirmations']['timeframes']['confirmations']);
        $mutations['daily cap'] = $document;
        $mutations['daily cap']['risk']['daily_loss_cap']['value']['absolute_quote'] = 41.0;
        $mutations['concurrency'] = $document;
        $mutations['concurrency']['risk']['max_concurrent_positions']['value']['limit'] = 4;
        $mutations['trade budget'] = $document;
        $mutations['trade budget']['risk']['trade_budget']['value'] = 3.0;
        $mutations['exposure'] = $document;
        $mutations['exposure']['risk']['mode_exposure_cap']['value'] = 76.0;
        $mutations['leverage'] = $document;
        $mutations['leverage']['leverage']['value'] = 4.0;
        $mutations['execution'] = $document;
        $mutations['execution']['timeframes']['execution'] = ['1m'];
        $mutations['confirmation'] = $document;
        $mutations['confirmation']['timeframes']['confirmations'] = ['5m'];
        $mutations['market fallback'] = $document;
        $mutations['market fallback']['order_policy']['value']['market_fallback'] = true;
        $mutations['data contract'] = $document;
        array_pop($mutations['data contract']['data_contract']['required_inputs']);
        $mutations['data contract field'] = $document;
        $mutations['data contract field']['data_contract']['required_inputs'][1]['fields'][2] = 'order_flow_imbalance';
        $mutations['trade budget source'] = $document;
        $mutations['trade budget source']['risk']['trade_budget']['source'] = 'synthetic-test';
        $mutations['trade budget justification'] = $document;
        $mutations['trade budget justification']['risk']['trade_budget']['justification'] = 'synthetic-test';
        $mutations['leverage source'] = $document;
        $mutations['leverage source']['leverage']['source'] = 'synthetic-test';
        $mutations['leverage justification'] = $document;
        $mutations['leverage justification']['leverage']['justification'] = 'synthetic-test';
        $mutations['order policy source'] = $document;
        $mutations['order policy source']['order_policy']['source'] = 'synthetic-test';
        $mutations['risk provenance'] = $document;
        $mutations['risk provenance']['provenance'][4]['source'] = 'synthetic-test';
        $mutations['missing provenance'] = $document;
        array_pop($mutations['missing provenance']['provenance']);
        $mutations['extra provenance'] = $document;
        $mutations['extra provenance']['provenance'][] = $document['provenance'][0];
        $mutations['provenance order'] = $document;
        [$mutations['provenance order']['provenance'][0], $mutations['provenance order']['provenance'][1]] = [$document['provenance'][1], $document['provenance'][0]];

        foreach ($mutations as $label => $mutation) {
            try {
                (new ModeContractValidator())->validate($mutation);
                self::fail('PHP accepted mutation: ' . $label);
            } catch (ModeContractException) {
                self::addToAssertionCount(1);
            }
            $object = json_decode(json_encode($mutation, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertFalse((new JsonSchemaValidator())->validate($object, $schema)->isValid(), 'schema accepted mutation: ' . $label);
        }
    }

    public function testFrozenShadowNumericLeavesHavePhpJsonSchemaParity(): void
    {
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/mode-contract.schema.json');
        $documents = [];
        $documents['scalping'] = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.1.0')->toArray();
        $documents['scalping']['risk']['trade_budget']['value'] = 2;
        $documents['scalping']['risk']['daily_loss_cap']['value']['percent_equity'] = 6;
        $documents['scalping']['risk']['daily_loss_cap']['value']['absolute_quote'] = 40;
        $documents['scalping']['risk']['max_concurrent_positions']['value']['limit'] = 3.0;
        $documents['scalping']['risk']['mode_exposure_cap']['value'] = 75;
        $documents['scalping']['leverage']['value'] = 3;
        $documents['day trading'] = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.1.0')->toArray();
        $documents['day trading']['risk']['trade_budget']['value'] = 5;
        $documents['day trading']['risk']['daily_loss_cap']['value']['percent_equity'] = 6;
        $documents['day trading']['risk']['daily_loss_cap']['value']['absolute_quote'] = 30;
        $documents['day trading']['risk']['max_concurrent_positions']['value']['limit'] = 4.0;
        $documents['day trading']['risk']['mode_exposure_cap']['value'] = 100;
        $documents['day trading']['leverage']['value'] = 2;

        foreach ($documents as $label => $document) {
            (new ModeContractValidator())->validate($document);
            $object = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertTrue((new JsonSchemaValidator())->validate($object, $schema)->isValid(), $label);
        }
    }

    public function testDayTradingShadowMutationsFailInPhpAndJsonSchema(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.1.0')->toArray();
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/mode-contract.schema.json');
        $mutations = [];
        $mutations['leverage'] = $document;
        $mutations['leverage']['leverage']['value'] = 3.0;
        $mutations['market fallback'] = $document;
        $mutations['market fallback']['order_policy']['value']['market_fallback'] = true;
        $mutations['missing confirmations'] = $document;
        unset($mutations['missing confirmations']['timeframes']['confirmations']);

        foreach ($mutations as $label => $mutation) {
            try {
                (new ModeContractValidator())->validate($mutation);
                self::fail('PHP accepted mutation: ' . $label);
            } catch (ModeContractException) {
                self::addToAssertionCount(1);
            }
            $object = json_decode(json_encode($mutation, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertFalse((new JsonSchemaValidator())->validate($object, $schema)->isValid(), 'schema accepted mutation: ' . $label);
        }
    }

    #[DataProvider('rejectedModeIds')]
    public function testRejectsLegacyAliasesAndUnknownModes(string $modeId): void
    {
        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('Unknown modern mode id');

        (new ModeContractLoader($this->contractRoot))->load($modeId, '1.0.0');
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedModeIds(): iterable
    {
        yield 'legacy regular' => ['regular'];
        yield 'legacy scalper' => ['scalper'];
        yield 'legacy scalper micro' => ['scalper_micro'];
        yield 'future swing mode' => ['swing_trading'];
        yield 'unclassified crash setup' => ['crash_short'];
        yield 'typo' => ['scalpping'];
    }

    public function testRejectsUnknownVersionWithoutFallback(): void
    {
        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('Unknown version "1.0.1" for modern mode "scalping"');

        (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.1');
    }

    public function testRejectsIncompleteContract(): void
    {
        $root = sys_get_temp_dir() . '/mode-contract-' . bin2hex(random_bytes(6));
        mkdir($root . '/day_trading', 0777, true);
        file_put_contents($root . '/day_trading/1.0.0.yaml', "schema_version: '1.0.0'\nmode_id: day_trading\nmode_version: '1.0.0'\n");

        try {
            $this->expectException(ModeContractException::class);
            $this->expectExceptionMessage('Missing required field "lifecycle"');
            (new ModeContractLoader($root))->load('day_trading', '1.0.0');
        } finally {
            unlink($root . '/day_trading/1.0.0.yaml');
            rmdir($root . '/day_trading');
            rmdir($root);
        }
    }

    public function testRejectsIdentityMismatchInsideContract(): void
    {
        $root = sys_get_temp_dir() . '/mode-contract-' . bin2hex(random_bytes(6));
        mkdir($root . '/scalping', 0777, true);
        $source = file_get_contents($this->contractRoot . '/scalping/1.0.0.yaml');
        self::assertIsString($source);
        file_put_contents($root . '/scalping/1.0.0.yaml', str_replace('mode_id: scalping', 'mode_id: day_trading', $source));

        try {
            $this->expectException(ModeContractException::class);
            $this->expectExceptionMessage('Contract identity does not match requested mode/version');
            (new ModeContractLoader($root))->load('scalping', '1.0.0');
        } finally {
            unlink($root . '/scalping/1.0.0.yaml');
            rmdir($root . '/scalping');
            rmdir($root);
        }
    }

    public function testMicroContractUsesActiveFiveMinuteContextOnly(): void
    {
        $contract = (new ModeContractLoader($this->contractRoot))->load('micro_scalping', '1.0.0');

        self::assertSame(['5m'], $contract->timeframeRoles()['context']);
        self::assertNotContains('15m', $contract->timeframeRoles()['context']);
        self::assertSame(['1m'], $contract->timeframeRoles()['execution']);
    }

    public function testUnresolvedConstraintPreventsExecutionEvenAfterLifecyclePromotion(): void
    {
        $loaded = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.0');
        $document = $loaded->toArray();
        $document['lifecycle']['status'] = 'active';
        $document['lifecycle']['executable'] = true;

        $contract = ModeContract::fromDocument($document);

        self::assertFalse($contract->isExecutable());
        self::assertContains('session_policy', $contract->unresolvedConstraints());
    }

    public function testRejectsWrongTypeForDefinedRiskBudget(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.0')->toArray();
        $document['risk']['trade_budget']['state'] = 'defined';
        $document['risk']['trade_budget']['value'] = 'two percent';

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('risk.trade_budget defined value must be a positive number');

        (new ModeContractValidator())->validate($document);
    }

    public function testAcceptsExplicitUsdtQuoteTradeBudgetDecision(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.0')->toArray();
        $document['risk']['trade_budget'] = [
            'state' => 'defined',
            'value' => ['amount' => 50.0, 'quote_currency' => 'USDT'],
            'unit' => 'quote_notional',
            'source' => 'synthetic-test',
            'justification' => 'Explicit quote budget for canonical trade-path propagation.',
        ];

        (new ModeContractValidator())->validate($document);
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/mode-contract.schema.json');
        $jsonDocument = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((new JsonSchemaValidator())->validate($jsonDocument, $schema)->isValid());
    }

    public function testRejectsSetupOutsideFrozenCatalog(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.0')->toArray();
        $document['compatible_setup_ids'][] = 'scalping.unreviewed.long';

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('compatible_setup_ids do not match the frozen catalog');

        (new ModeContractValidator())->validate($document);
    }

    public function testRejectsDuplicateTimeframeAndUnknownUnit(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.0')->toArray();
        $document['timeframes']['execution'] = ['5m', '5m'];
        $document['risk']['daily_loss_cap']['unit'] = 'bananas';

        $this->expectException(ModeContractException::class);

        (new ModeContractValidator())->validate($document);
    }

    public function testRejectsArbitraryDefinedCadenceValue(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.0')->toArray();
        $document['cadence']['evaluation'] = [
            'state' => 'defined', 'value' => ['nonsense' => true], 'unit' => 'duration',
            'source' => 'test', 'justification' => 'test mutation',
        ];

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('cadence.evaluation defined value must be an ISO-8601 duration');

        (new ModeContractValidator())->validate($document);
    }

    public function testRejectsUnitOwnedByAnotherContractField(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.0.0')->toArray();
        $document['session_policy']['unit'] = 'positions';

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('session_policy.unit must be "session_policy"');

        (new ModeContractValidator())->validate($document);
    }

    public function testRejectsUnsupportedTimeframeInsideDataContract(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.0.0')->toArray();
        $document['data_contract']['required_inputs'][0]['timeframes'][] = '99m';

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('Unsupported timeframe "99m" in data_contract.required_inputs[].timeframes');

        (new ModeContractValidator())->validate($document);
    }

    public function testContractsExposeExactAuditedProvenancePaths(): void
    {
        $scalping = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.0')->toArray();
        $micro = (new ModeContractLoader($this->contractRoot))->load('micro_scalping', '1.0.0')->toArray();

        self::assertStringContainsString(
            'src/TradeEntry/Builder/TradeEntryRequestBuilder.php:80',
            $scalping['risk']['trade_budget']['source'],
        );
        self::assertSame(
            'src/MtfValidator/config/validations.scalper_micro.yaml:87-101,109-125',
            $micro['provenance'][4]['source'],
        );
    }

    #[DataProvider('invalidDurations')]
    public function testRejectsInvalidIsoDuration(string $duration): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.0.0')->toArray();
        $document['cadence']['evaluation']['state'] = 'defined';
        $document['cadence']['evaluation']['value'] = $duration;

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('ISO-8601 duration');
        (new ModeContractValidator())->validate($document);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDurations(): iterable
    {
        yield 'garbage after P' => ['Pgarbage'];
        yield 'space after P' => ['P space'];
    }

    public function testAcceptsValidIsoDuration(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.0.0')->toArray();
        $document['cadence']['evaluation']['state'] = 'defined';
        $document['cadence']['evaluation']['value'] = 'PT5M';

        (new ModeContractValidator())->validate($document);
        self::addToAssertionCount(1);
    }

    #[DataProvider('nonFiniteYamlValues')]
    public function testYamlLoaderRejectsNonFiniteDailyCap(string $field, string $yamlValue): void
    {
        $root = sys_get_temp_dir() . '/mode-contract-' . bin2hex(random_bytes(6));
        mkdir($root . '/day_trading', 0777, true);
        $source = file_get_contents($this->contractRoot . '/day_trading/1.0.0.yaml');
        self::assertIsString($source);
        $needle = $field === 'percent_equity' ? 'percent_equity: 6.0' : 'absolute_quote: 30.0';
        file_put_contents($root . '/day_trading/1.0.0.yaml', str_replace($needle, $field . ': ' . $yamlValue, $source));

        try {
            $this->expectException(ModeContractException::class);
            (new ModeContractLoader($root))->load('day_trading', '1.0.0');
        } finally {
            unlink($root . '/day_trading/1.0.0.yaml');
            rmdir($root . '/day_trading');
            rmdir($root);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonFiniteYamlValues(): iterable
    {
        yield 'infinite percent' => ['percent_equity', '.inf'];
        yield 'NaN quote' => ['absolute_quote', '.nan'];
    }

    public function testFactoryRejectsUnpublishedSemanticVersion(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.0.0')->toArray();
        $document['mode_version'] = '9.9.9';

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('Unsupported published version');
        ModeContract::fromDocument($document);
    }

    #[DataProvider('nonListContractFields')]
    public function testRejectsAssociativeArrayWhereListIsRequired(string $field): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.0.0')->toArray();
        if ($field === 'required_inputs') {
            $document['data_contract']['required_inputs'] = [1 => $document['data_contract']['required_inputs'][0]];
        } else {
            $document['provenance'] = [1 => $document['provenance'][0]];
        }

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage('must be a non-empty list');
        (new ModeContractValidator())->validate($document);
    }

    /** @return iterable<string, array{string}> */
    public static function nonListContractFields(): iterable
    {
        yield 'required inputs' => ['required_inputs'];
        yield 'provenance' => ['provenance'];
    }

    #[DataProvider('invalidGovernanceTransitions')]
    public function testRejectsInvalidGovernanceTransition(string $rule, string $target): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.0.0')->toArray();
        $document['governance'][$rule]['target_status'] = $target;

        $this->expectException(ModeContractException::class);
        $this->expectExceptionMessage(sprintf('governance.%s.target_status must be', $rule));
        (new ModeContractValidator())->validate($document);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidGovernanceTransitions(): iterable
    {
        yield 'promotion cannot skip shadow' => ['promotion', 'active'];
        yield 'suspension must return draft' => ['suspension', 'retired'];
        yield 'rollback must retire version' => ['rollback', 'draft'];
    }

    public function testFactoryRejectsUnvalidatedLegacyDocument(): void
    {
        $document = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.0.0')->toArray();
        $document['mode_id'] = 'regular';
        $document['lifecycle'] = ['status' => 'active', 'executable' => true, 'rationale' => 'bypass'];

        $this->expectException(ModeContractException::class);
        ModeContract::fromDocument($document);
    }

    public function testSchemaAndPublishedFixturesAreMachineReadable(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $schema = json_decode((string) file_get_contents($projectRoot . '/config/trading/schema/mode-contract.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $valid = json_decode((string) file_get_contents($projectRoot . '/tests/Fixtures/TradingCore/Mode/valid-day-trading.json'), true, 512, JSON_THROW_ON_ERROR);
        $invalid = json_decode((string) file_get_contents($projectRoot . '/tests/Fixtures/TradingCore/Mode/invalid-legacy-alias.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('day_trading', $valid['mode_id']);
        self::assertSame('regular', $invalid['mode_id']);
        self::assertSame(ModeContractValidator::MODE_IDS, $schema['properties']['mode_id']['enum']);
        (new ModeContractValidator())->validate($valid);

        $this->expectException(ModeContractException::class);
        (new ModeContractValidator())->validate($invalid);
    }

    public function testDraft202012SchemaAcceptsAllPublishedContractsAndValidFixture(): void
    {
        $schema = $this->jsonObject(dirname(__DIR__, 3) . '/config/trading/schema/mode-contract.schema.json');
        $validator = new JsonSchemaValidator();

        foreach (glob($this->contractRoot . '/*/*.yaml') ?: [] as $path) {
            $document = json_decode(json_encode(Yaml::parseFile($path), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($validator->validate($document, $schema)->isValid(), $path);
        }

        $valid = $this->jsonObject(dirname(__DIR__, 3) . '/tests/Fixtures/TradingCore/Mode/valid-day-trading.json');
        self::assertTrue($validator->validate($valid, $schema)->isValid());
        $invalid = $this->jsonObject(dirname(__DIR__, 3) . '/tests/Fixtures/TradingCore/Mode/invalid-legacy-alias.json');
        self::assertFalse($validator->validate($invalid, $schema)->isValid());
    }

    public function testDraft202012SchemaRejectsParityMutationCorpus(): void
    {
        $root = dirname(__DIR__, 3);
        $schema = $this->jsonObject($root . '/config/trading/schema/mode-contract.schema.json');
        $base = json_decode(json_encode(Yaml::parseFile($this->contractRoot . '/day_trading/1.0.0.yaml'), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $mutations = [];
        foreach (['Pgarbage', 'P space'] as $duration) {
            $copy = clone $base;
            $copy->cadence = clone $base->cadence;
            $copy->cadence->evaluation = clone $base->cadence->evaluation;
            $copy->cadence->evaluation->state = 'defined';
            $copy->cadence->evaluation->value = $duration;
            $mutations['duration ' . $duration] = $copy;
        }
        $version = clone $base;
        $version->mode_version = '9.9.9';
        $mutations['unknown version'] = $version;
        $transition = clone $base;
        $transition->governance = clone $base->governance;
        $transition->governance->promotion = clone $base->governance->promotion;
        $transition->governance->promotion->target_status = 'active';
        $mutations['invalid transition'] = $transition;
        $whitespace = clone $base;
        $whitespace->lifecycle = clone $base->lifecycle;
        $whitespace->lifecycle->rationale = '   ';
        $mutations['whitespace string'] = $whitespace;
        $nonList = clone $base;
        $nonList->provenance = (object) ['1' => $base->provenance[0]];
        $mutations['non-list provenance'] = $nonList;

        $validator = new JsonSchemaValidator();
        foreach ($mutations as $label => $mutation) {
            self::assertFalse($validator->validate($mutation, $schema)->isValid(), $label);
        }
    }

    private function jsonObject(string $path): object
    {
        $decoded = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($decoded);

        return $decoded;
    }
}
