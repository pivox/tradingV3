<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class HyperliquidTestnetSmokeComposeContractTest extends TestCase
{
    public function testSignerProfileIsInternalFixedTestnetAndPrivateKeyIsSignerOnly(): void
    {
        $root = dirname(__DIR__, 3);
        $compose = Yaml::parseFile($root . '/docker-compose.yml');
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);
        $signer = $services['hyperliquid-signer'] ?? null;
        self::assertIsArray($signer);

        self::assertSame(['hyperliquid-testnet'], $signer['profiles'] ?? null);
        self::assertArrayNotHasKey('ports', $signer);
        self::assertSame(['8098'], $signer['expose'] ?? null);
        self::assertSame('testnet', $signer['environment']['HYPERLIQUID_ENV'] ?? null);
        self::assertSame('testnet', $signer['environment']['HYPERLIQUID_NETWORK'] ?? null);
        self::assertSame(
            'https://api.hyperliquid-testnet.xyz',
            $signer['environment']['HYPERLIQUID_API_BASE_URI'] ?? null,
        );
        self::assertSame('${HYPERLIQUID_SIGNER_BROADCAST_ENABLED:-0}', $signer['environment']['HYPERLIQUID_SIGNER_BROADCAST_ENABLED'] ?? null);
        self::assertArrayHasKey('HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY', $signer['environment']);

        $phpEnvironment = $services['trading-app-php']['environment'] ?? null;
        self::assertIsArray($phpEnvironment);
        self::assertSame('${DEMO_TRADING_ENABLED:-0}', $phpEnvironment['DEMO_TRADING_ENABLED'] ?? null);
        self::assertSame('${HYPERLIQUID_TESTNET_TRADING_ENABLED:-0}', $phpEnvironment['HYPERLIQUID_TESTNET_TRADING_ENABLED'] ?? null);
        self::assertArrayNotHasKey('HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY', $phpEnvironment);
        self::assertArrayNotHasKey('HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY', $compose['x-global-variables'] ?? []);

        foreach ($services as $serviceName => $service) {
            if ($serviceName === 'hyperliquid-signer' || !is_array($service)) {
                continue;
            }
            self::assertArrayNotHasKey(
                'HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY',
                is_array($service['environment'] ?? null) ? $service['environment'] : [],
                sprintf('Private key leaked to service %s.', $serviceName),
            );
        }
    }

    public function testExampleEnvironmentKeepsAllMutationCredentialsAndFlagsDisabled(): void
    {
        $root = dirname(__DIR__, 3);
        $values = (new Dotenv())->parse((string) file_get_contents($root . '/.env.example'));

        self::assertSame('0', $values['DEMO_TRADING_ENABLED'] ?? null);
        self::assertSame('0', $values['HYPERLIQUID_TESTNET_TRADING_ENABLED'] ?? null);
        self::assertSame('0', $values['HYPERLIQUID_SIGNER_BROADCAST_ENABLED'] ?? null);
        self::assertSame('', $values['HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS'] ?? null);
        self::assertSame('', $values['HYPERLIQUID_TESTNET_AGENT_ADDRESS'] ?? null);
        self::assertSame('', $values['HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY'] ?? null);
        self::assertSame('', $values['HYPERLIQUID_SIGNER_AUTH_TOKEN'] ?? null);
    }
}
