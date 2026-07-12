<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Hyperliquid;

use App\Exchange\Hyperliquid\HyperliquidAgentSigner;
use App\Exchange\Hyperliquid\HyperliquidSigningPayloadFactory;
use App\Provider\Hyperliquid\FakeHyperliquidSigner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HyperliquidSignerTest extends TestCase
{
    public function testPhpAgentSignerAlwaysRejectsKeyCustody(): void
    {
        $signer = new HyperliquidAgentSigner();

        $this->expectExceptionMessage('hyperliquid_php_key_custody_forbidden');
        $signer->signAction(['type' => 'order'], 1);
    }

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

}
