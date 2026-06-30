<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Hyperliquid;

use App\Exchange\Hyperliquid\HyperliquidAgentSigner;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidSignatureBackendInterface;
use App\Exchange\Hyperliquid\HyperliquidSigningPayloadFactory;
use App\Provider\Hyperliquid\FakeHyperliquidSigner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HyperliquidSignerTest extends TestCase
{
    public function testCanonicalPayloadIsStableAndSortsNestedKeys(): void
    {
        $factory = new HyperliquidSigningPayloadFactory();

        $payload = $factory->canonicalPayload(
            [
                'orders' => [[
                    's' => '1',
                    'p' => '100',
                    't' => ['limit' => ['tif' => 'Gtc']],
                    'b' => true,
                    'a' => 0,
                    'c' => '0xabc',
                ]],
                'type' => 'order',
            ],
            1_700_000_000_000,
            'testnet',
            '0x0000000000000000000000000000000000000001',
        );

        self::assertSame(
            '{"action":{"orders":[{"a":0,"b":true,"c":"0xabc","p":"100","s":"1","t":{"limit":{"tif":"Gtc"}}}],"type":"order"},"network":"testnet","nonce":1700000000000,"signer":"0x0000000000000000000000000000000000000001"}',
            $payload,
        );
    }

    public function testFakeSignerIsDeterministicAndNonSecret(): void
    {
        $signer = new FakeHyperliquidSigner('testnet-fixture-seed');
        $action = ['type' => 'cancel', 'cancels' => [['a' => 0, 'o' => 12345]]];

        $first = $signer->signAction($action, 42);
        $second = $signer->signAction($action, 42);

        self::assertSame($first, $second);
        self::assertSame($action, $first['action']);
        self::assertSame(42, $first['nonce']);
        self::assertSame('testnet', $first['network']);
        self::assertSame('fake_hyperliquid_signer', $first['signature']['scheme']);
        self::assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $first['signature']['r']);
        self::assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $first['signature']['s']);
        self::assertContains($first['signature']['v'], [27, 28]);
        self::assertStringNotContainsString('testnet-fixture-seed', json_encode($first, \JSON_THROW_ON_ERROR));
    }

    public function testAgentSignerDelegatesToBackendAndRedactsSecret(): void
    {
        $backend = new class implements HyperliquidSignatureBackendInterface {
            public string $capturedPayload = '';
            public string $capturedPrivateKey = '';

            public function sign(string $canonicalPayload, string $privateKey): array
            {
                $this->capturedPayload = $canonicalPayload;
                $this->capturedPrivateKey = $privateKey;

                return [
                    'r' => '0x' . str_repeat('1', 64),
                    's' => '0x' . str_repeat('2', 64),
                    'v' => 27,
                ];
            }
        };
        $config = new HyperliquidConfig(
            environment: 'testnet',
            network: 'testnet',
            testnetAgentPrivateKey: 'fixture-agent-material',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        );

        $signed = (new HyperliquidAgentSigner($config, $backend))->signAction(['type' => 'updateLeverage', 'asset' => 0], 99);

        self::assertSame('fixture-agent-material', $backend->capturedPrivateKey);
        self::assertStringContainsString('"network":"testnet"', $backend->capturedPayload);
        self::assertSame('0x0000000000000000000000000000000000000002', $signed['signer']);
        self::assertSame('0x0000000000000000000000000000000000000001', $signed['account']);
        self::assertSame(['r' => '0x' . str_repeat('1', 64), 's' => '0x' . str_repeat('2', 64), 'v' => 27], $signed['signature']);
        self::assertTrue($signed['redacted']);
        self::assertStringNotContainsString('fixture-agent-material', json_encode($signed, \JSON_THROW_ON_ERROR));
    }

    public function testAgentSignerRejectsWrongDomainAndMainnet(): void
    {
        $wrongDomain = new HyperliquidAgentSigner(new HyperliquidConfig(
            environment: 'testnet',
            network: 'devnet',
            testnetAgentPrivateKey: 'fixture-agent-material',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_signing_network_must_be_testnet');
        $wrongDomain->signAction(['type' => 'cancel'], 1);
    }

    public function testAgentSignerRejectsMainnetEvenWhenSecretIsPresent(): void
    {
        $signer = new HyperliquidAgentSigner(new HyperliquidConfig(
            environment: 'mainnet',
            network: 'mainnet',
            mainnetEnabled: true,
            testnetAgentPrivateKey: 'fixture-agent-material',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        ));

        try {
            $signer->signAction(['type' => 'cancel'], 1);
            self::fail('Expected Hyperliquid mainnet signing to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_signer_mainnet_rejected', $exception->getMessage());
            self::assertStringNotContainsString('fixture-agent-material', $exception->getMessage());
        }
    }

    public function testTradingConfigRejectsMainnetBeforeAcceptingTestnetSignerCredentials(): void
    {
        $config = new HyperliquidConfig(
            environment: 'mainnet',
            mainnetEnabled: true,
            network: 'mainnet',
            testnetAgentPrivateKey: 'fixture-agent-material',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_trading_mainnet_rejected');
        $config->assertTradingConfigured();
    }

    public function testTradingConfigRejectsUnknownEnvironmentBeforeAcceptingTestnetSignerCredentials(): void
    {
        $config = new HyperliquidConfig(
            environment: 'prod',
            network: 'testnet',
            testnetAgentPrivateKey: 'fixture-agent-material',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_trading_environment_must_be_testnet');
        $config->assertTradingConfigured();
    }

    public function testTradingConfigRejectsBlankEnvironmentBeforeAcceptingTestnetSignerCredentials(): void
    {
        $config = new HyperliquidConfig(
            environment: '',
            network: 'testnet',
            testnetAgentPrivateKey: 'fixture-agent-material',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_trading_environment_must_be_testnet');
        $config->assertTradingConfigured();
    }

    public function testAgentSignerRejectsUnknownEnvironmentBeforeAcceptingTestnetSignerCredentials(): void
    {
        $signer = new HyperliquidAgentSigner(new HyperliquidConfig(
            environment: 'devnet',
            network: 'testnet',
            testnetAgentPrivateKey: 'fixture-agent-material',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_signing_environment_must_be_testnet');
        $signer->signAction(['type' => 'cancel'], 1);
    }

    public function testAgentSignerRejectsBlankEnvironmentBeforeAcceptingTestnetSignerCredentials(): void
    {
        $signer = new HyperliquidAgentSigner(new HyperliquidConfig(
            environment: '',
            network: 'testnet',
            testnetAgentPrivateKey: 'fixture-agent-material',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_signing_environment_must_be_testnet');
        $signer->signAction(['type' => 'cancel'], 1);
    }

    public function testAgentSignerRejectsMissingTestnetSecret(): void
    {
        $signer = new HyperliquidAgentSigner(new HyperliquidConfig(
            environment: 'testnet',
            network: 'testnet',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY is required for Hyperliquid testnet signing.');
        $signer->signAction(['type' => 'cancel'], 1);
    }
}
