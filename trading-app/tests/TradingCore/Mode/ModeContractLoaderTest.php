<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Mode;

use App\TradingCore\Mode\Exception\ModeContractException;
use App\TradingCore\Mode\ModeContract;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Mode\ModeContractValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            'src/MtfValidator/config/validations.scalper_micro.yaml:109-125',
            $micro['provenance'][4]['source'],
        );
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
}
