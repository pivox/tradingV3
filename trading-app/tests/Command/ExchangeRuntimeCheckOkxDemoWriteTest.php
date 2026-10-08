<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ExchangeRuntimeCheckCommand;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketEndpointGuard;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use App\Provider\Okx\OkxDemoWriteRuntimeCheck;
use App\Provider\Registry\ExchangeProviderRegistry;
use App\Tests\Exchange\Okx\Demo\OkxDemoWriteHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExchangeRuntimeCheckCommand::class)]
final class ExchangeRuntimeCheckOkxDemoWriteTest extends TestCase
{
    public function testJsonOptionPrintsTheEnvelopeCheckWithoutSecrets(): void
    {
        $tester = $this->tester(new OkxDemoWriteHarness(OkxDemoWriteHarness::config()));

        self::assertSame(Command::SUCCESS, $tester->execute(['exchange' => 'okx', 'market_type' => 'perpetual', '--json' => true]));

        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('okx', $payload['exchange']);
        self::assertTrue($payload['write_ready']);
        self::assertTrue($payload['flags']['OKX_SIMULATED_TRADING']);
        self::assertStringNotContainsString(OkxDemoWriteHarness::SECRET, $tester->getDisplay());
    }

    public function testJsonOptionIsRejectedForOtherExchanges(): void
    {
        $tester = $this->tester(new OkxDemoWriteHarness(OkxDemoWriteHarness::config()));

        self::assertSame(Command::FAILURE, $tester->execute(['exchange' => 'fake', 'market_type' => 'perpetual', '--json' => true]));
    }

    private function tester(OkxDemoWriteHarness $harness): CommandTester
    {
        $command = new ExchangeRuntimeCheckCommand(
            new ExchangeAdapterRegistry([]),
            new ExchangeProviderRegistry([]),
            $harness->config,
            new HyperliquidConfig(),
            okxDemoWriteRuntimeCheck: new OkxDemoWriteRuntimeCheck($harness->config, $harness->gate(), new OkxPrivateWebSocketEndpointGuard()),
        );

        return new CommandTester($command);
    }
}
