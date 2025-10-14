<?php

declare(strict_types=1);

namespace App\Tests\Domain\Trading\Service;

use App\Config\TradingParameters;
use App\Domain\Ports\Out\TradingProviderPort;
use App\Domain\Trading\Service\TradeContextService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TradeContextServiceTest extends TestCase
{
    public function testAccountBalanceFallsBackToConfig(): void
    {
        $provider = new class implements TradingProviderPort {
            public function submitOrder(array $orderData): array { return []; }
            public function cancelOrder(string $symbol, string $orderId): array { return []; }
            public function cancelAllOrders(string $symbol): array { return []; }
            public function getPositions(?string $symbol = null): array { return []; }
            public function getAssetsDetail(): array { throw new \RuntimeException('API unavailable'); }
            public function healthCheck(): array { return []; }
            public function setLeverage(string $symbol, int $leverage, string $openType = 'cross'): array { return []; }
            public function submitTpSlOrder(array $payload): array { return []; }
        };

        $parameters = new TradingParameters(projectDir: dirname(__DIR__, 4));
        $previous = getenv('TRADING_ACCOUNT_BALANCE');
        putenv('TRADING_ACCOUNT_BALANCE'); // ensure unset
        $service = new TradeContextService($provider, $parameters, new NullLogger());

        $balance = $service->getAccountBalance();
        self::assertSame(100.0, $balance); // from trading.yml entry budget fixed

        if ($previous !== false) {
            putenv('TRADING_ACCOUNT_BALANCE=' . $previous);
        } else {
            putenv('TRADING_ACCOUNT_BALANCE');
        }
    }

    public function testAccountBalanceExtractsAvailableUsdt(): void
    {
        $provider = new class implements TradingProviderPort {
            public function submitOrder(array $orderData): array { return []; }
            public function cancelOrder(string $symbol, string $orderId): array { return []; }
            public function cancelAllOrders(string $symbol): array { return []; }
            public function getPositions(?string $symbol = null): array { return []; }
            public function getAssetsDetail(): array {
                return [
                    'code' => 1000,
                    'data' => [
                        'assets' => [
                            ['coin_code' => 'USDT', 'available_balance' => '1234.56'],
                        ],
                    ],
                ];
            }
            public function healthCheck(): array { return []; }
            public function setLeverage(string $symbol, int $leverage, string $openType = 'cross'): array { return []; }
            public function submitTpSlOrder(array $payload): array { return []; }
        };

        $parameters = new TradingParameters(projectDir: dirname(__DIR__, 4));
        $service = new TradeContextService($provider, $parameters, new NullLogger());

        self::assertSame(1234.56, $service->getAccountBalance());
    }

    public function testTimeframeMultiplierUsesConfig(): void
    {
        $provider = new class implements TradingProviderPort {
            public function submitOrder(array $orderData): array { return []; }
            public function cancelOrder(string $symbol, string $orderId): array { return []; }
            public function cancelAllOrders(string $symbol): array { return []; }
            public function getPositions(?string $symbol = null): array { return []; }
            public function getAssetsDetail(): array { return []; }
            public function healthCheck(): array { return []; }
            public function setLeverage(string $symbol, int $leverage, string $openType = 'cross'): array { return []; }
            public function submitTpSlOrder(array $payload): array { return []; }
        };

        $parameters = new TradingParameters(projectDir: dirname(__DIR__, 4));
        $service = new TradeContextService($provider, $parameters, new NullLogger());

        self::assertSame(0.5, $service->getTimeframeMultiplier('1m'));
        self::assertSame(1.0, $service->getTimeframeMultiplier('4h'));
        self::assertSame(1.0, $service->getTimeframeMultiplier('unknown'));
    }

    public function testRiskPercentage(): void
    {
        $provider = new class implements TradingProviderPort {
            public function submitOrder(array $orderData): array { return []; }
            public function cancelOrder(string $symbol, string $orderId): array { return []; }
            public function cancelAllOrders(string $symbol): array { return []; }
            public function getPositions(?string $symbol = null): array { return []; }
            public function getAssetsDetail(): array { return []; }
            public function healthCheck(): array { return []; }
            public function setLeverage(string $symbol, int $leverage, string $openType = 'cross'): array { return []; }
            public function submitTpSlOrder(array $payload): array { return []; }
        };

        $parameters = new TradingParameters(projectDir: dirname(__DIR__, 4));
        $service = new TradeContextService($provider, $parameters, new NullLogger());

        self::assertSame(5.0, $service->getRiskPercentage());
    }
}
