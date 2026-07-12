<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
#[Group('integration')]
final class HyperliquidTestnetSmokeComposeIntegrationTest extends TestCase
{
    private const PRIVATE_KEY_SENTINEL = 'TASK8_PRIVATE_KEY_SENTINEL_DO_NOT_USE';

    public function testRenderedTestnetProfileKeepsSignerPrivateAndDisabled(): void
    {
        $root = dirname(__DIR__, 3);
        $version = new Process(['docker', 'compose', 'version'], $root);
        try {
            $version->run();
        } catch (\Throwable) {
            self::markTestSkipped('docker compose binary unavailable');
        }
        if (!$version->isSuccessful()) {
            self::markTestSkipped('docker compose binary unavailable');
        }

        $process = new Process(
            ['docker', 'compose', '--profile', 'hyperliquid-testnet', 'config', '--format', 'json'],
            $root,
            [
                'HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY' => self::PRIVATE_KEY_SENTINEL,
                'DEMO_TRADING_ENABLED' => '0',
                'HYPERLIQUID_TESTNET_TRADING_ENABLED' => '0',
                'HYPERLIQUID_SIGNER_BROADCAST_ENABLED' => '0',
            ],
        );
        $process->mustRun();
        $rendered = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($rendered);
        $services = $rendered['services'] ?? null;
        self::assertIsArray($services);
        $signer = $services['hyperliquid-signer'] ?? null;
        self::assertIsArray($signer);

        self::assertSame(['hyperliquid-testnet'], $signer['profiles'] ?? null);
        self::assertArrayNotHasKey('ports', $signer);
        self::assertSame(['8098'], $signer['expose'] ?? null);
        self::assertArrayHasKey('trading-app-net', $signer['networks'] ?? []);
        self::assertSame('testnet', $signer['environment']['HYPERLIQUID_ENV'] ?? null);
        self::assertSame('testnet', $signer['environment']['HYPERLIQUID_NETWORK'] ?? null);
        self::assertSame('https://api.hyperliquid-testnet.xyz', $signer['environment']['HYPERLIQUID_API_BASE_URI'] ?? null);
        self::assertSame('0', $signer['environment']['HYPERLIQUID_SIGNER_BROADCAST_ENABLED'] ?? null);
        self::assertSame(self::PRIVATE_KEY_SENTINEL, $signer['environment']['HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY'] ?? null);

        $php = $services['trading-app-php']['environment'] ?? null;
        self::assertIsArray($php);
        self::assertSame('0', $php['DEMO_TRADING_ENABLED'] ?? null);
        self::assertSame('0', $php['HYPERLIQUID_TESTNET_TRADING_ENABLED'] ?? null);
        self::assertArrayNotHasKey('HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY', $php);
        self::assertSame(1, $this->sentinelOccurrences($rendered));
    }

    private function sentinelOccurrences(mixed $value): int
    {
        if (is_array($value)) {
            return array_sum(array_map($this->sentinelOccurrences(...), $value));
        }

        return $value === self::PRIVATE_KEY_SENTINEL ? 1 : 0;
    }
}
