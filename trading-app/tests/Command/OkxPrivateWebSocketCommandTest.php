<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\OkxPrivateWebSocketCommand;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketEndpointGuard;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OkxPrivateWebSocketCommand::class)]
final class OkxPrivateWebSocketCommandTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidConfigurations')]
    public function testCommandRejectsUnsafeConfigurationBeforeStartingWorker(
        array $overrides,
        string $expectedCode,
    ): void {
        $worker = (new \ReflectionClass(OkxPrivateWebSocketWorker::class))->newInstanceWithoutConstructor();
        $command = new OkxPrivateWebSocketCommand(
            $worker,
            $this->config($overrides),
            new OkxPrivateWebSocketEndpointGuard(),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame("status=refused code={$expectedCode}\n", $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'environment is case-sensitive' => [
            ['environment' => 'Demo'],
            'okx_private_ws_environment_invalid',
        ];
        yield 'simulated trading disabled' => [
            ['simulatedTrading' => false],
            'okx_private_ws_simulated_trading_required',
        ];
        yield 'live enabled' => [
            ['liveEnabled' => true],
            'okx_private_ws_live_enabled',
        ];
        yield 'api key empty' => [
            ['apiKey' => ''],
            'okx_private_ws_credentials_missing',
        ];
        yield 'secret empty' => [
            ['apiSecret' => '  '],
            'okx_private_ws_credentials_missing',
        ];
        yield 'passphrase empty' => [
            ['apiPassphrase' => ''],
            'okx_private_ws_credentials_missing',
        ];
        yield 'endpoint not allowlisted' => [
            ['wsPrivateUri' => 'wss://ws.okx.com:8443/ws/v5/private'],
            'okx_demo_private_ws_endpoint_not_allowed',
        ];
        yield 'business endpoint not allowlisted' => [
            ['wsBusinessUri' => 'wss://ws.okx.com:8443/ws/v5/business'],
            'okx_demo_private_ws_endpoint_not_allowed',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides): OkxConfig
    {
        $values = array_replace([
            'environment' => 'demo',
            'apiKey' => 'demo-key',
            'apiSecret' => 'demo-secret',
            'apiPassphrase' => 'demo-passphrase',
            'wsPrivateUri' => 'wss://wseeapap.okx.com:8443/ws/v5/private',
            'wsBusinessUri' => 'wss://wseeapap.okx.com:8443/ws/v5/business',
            'simulatedTrading' => true,
            'liveEnabled' => false,
        ], $overrides);

        return new OkxConfig(...$values);
    }
}
