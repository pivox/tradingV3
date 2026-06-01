<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ExchangeRuntimeCheckCommand;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Okx\OkxConfig;
use App\Provider\Bitmart\Http\BitmartConfig;
use App\Provider\Context\ExchangeContext;
use App\Provider\Registry\ExchangeProviderBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExchangeRuntimeCheckCommand::class)]
final class ExchangeRuntimeCheckCommandTest extends TestCase
{
    public function testReportsUnreadyOkxRuntimeWithoutProviderOrCredentials(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::OKX, MarketType::PERPETUAL)),
            $this->missingProviderRegistry(),
            new BitmartConfig('', '', ''),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: okx', $output);
        self::assertStringContainsString('Market type: perpetual', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: missing', $output);
        self::assertStringContainsString('Credentials: missing', $output);
        self::assertStringContainsString('REST: unknown', $output);
        self::assertStringContainsString('Private WS: unsupported', $output);
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testReportsReadyBitmartRuntimeWhenAdapterAndProviderExist(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::BITMART, MarketType::PERPETUAL)),
            $this->providerRegistry($this->providerBundle(Exchange::BITMART, MarketType::PERPETUAL)),
            new BitmartConfig('key', 'secret', 'memo'),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: bitmart', $output);
        self::assertStringContainsString('Market type: perpetual', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: found', $output);
        self::assertStringContainsString('Credentials: ok', $output);
        self::assertStringContainsString('REST: unknown', $output);
        self::assertStringContainsString('Recommended dry_run: false', $output);
        self::assertStringContainsString('Schedule ready: yes', $output);
    }

    public function testReportsUnreadyBitmartRuntimeWhenCredentialsAreMissing(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::BITMART, MarketType::PERPETUAL)),
            $this->providerRegistry($this->providerBundle(Exchange::BITMART, MarketType::PERPETUAL)),
            new BitmartConfig('', '', ''),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: bitmart', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: found', $output);
        self::assertStringContainsString('Credentials: missing', $output);
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: yes', $output);
    }

    private function adapter(Exchange $exchange, MarketType $marketType, bool $supportsPrivateWs = false): ExchangeAdapterInterface
    {
        $adapter = $this->createMock(ExchangeAdapterInterface::class);
        $adapter->method('exchange')->willReturn($exchange);
        $adapter->method('marketType')->willReturn($marketType);
        $adapter->method('capabilities')->willReturn(new ExchangeCapabilities(
            supportsWebSocketPrivate: $supportsPrivateWs,
        ));

        return $adapter;
    }

    private function adapterRegistry(ExchangeAdapterInterface $adapter): ExchangeAdapterRegistryInterface
    {
        $registry = $this->createMock(ExchangeAdapterRegistryInterface::class);
        $registry
            ->method('get')
            ->with($adapter->exchange(), $adapter->marketType())
            ->willReturn($adapter);

        return $registry;
    }

    private function missingProviderRegistry(): ExchangeProviderRegistryInterface
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry
            ->method('get')
            ->willThrowException(new \RuntimeException('provider missing'));

        return $registry;
    }

    private function providerRegistry(ExchangeProviderBundle $bundle): ExchangeProviderRegistryInterface
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry
            ->method('get')
            ->with(self::callback(static fn (ExchangeContext $context): bool => (string) $context === (string) $bundle->context()))
            ->willReturn($bundle);

        return $registry;
    }

    private function providerBundle(Exchange $exchange, MarketType $marketType): ExchangeProviderBundle
    {
        return new ExchangeProviderBundle(
            new ExchangeContext($exchange, $marketType),
            $this->createMock(KlineProviderInterface::class),
            $this->createMock(ContractProviderInterface::class),
            $this->createMock(OrderProviderInterface::class),
            $this->createMock(AccountProviderInterface::class),
            $this->createMock(SystemProviderInterface::class),
        );
    }
}
