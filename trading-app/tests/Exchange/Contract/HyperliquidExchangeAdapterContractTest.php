<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Contract;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\HyperliquidExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;

#[CoversClass(HyperliquidExchangeAdapter::class)]
#[CoversClass(HyperliquidActionFactory::class)]
#[CoversClass(HyperliquidAssetResolver::class)]
#[CoversClass(HyperliquidConfig::class)]
final class HyperliquidExchangeAdapterContractTest extends ExchangeAdapterContractTestCase
{
    private HyperliquidExchangeAdapter $adapter;

    protected function setUp(): void
    {
        $client = new ContractHyperliquidClient();
        $actions = new HyperliquidActionFactory();
        $this->adapter = new HyperliquidExchangeAdapter(
            $client,
            new HyperliquidAssetResolver($client),
            $actions,
            new HyperliquidConfig(
                environment: 'testnet',
                network: 'testnet',
                testnetAgentAddress: '0x0000000000000000000000000000000000000002',
                testnetAccountAddress: '0x0000000000000000000000000000000000000001',
            ),
            $this->fixedClock(),
        );
    }

    protected function adapter(): ExchangeAdapterInterface
    {
        return $this->adapter;
    }

    protected function exchange(): Exchange
    {
        return Exchange::HYPERLIQUID;
    }

    protected function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    protected function symbol(): string
    {
        return 'BTCUSDC';
    }

    protected function snapshotClientOrderId(string $clientOrderId): string
    {
        return (new HyperliquidActionFactory())->cloid($clientOrderId);
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }
}

final class ContractHyperliquidClient implements HyperliquidRestClientInterface
{
    private int $nextOrderId = 90000;

    /** @var array<string,array<string,mixed>> */
    private array $orders = [];

    /** @var array<string,string> */
    private array $cloidIndex = [];

    public function info(array $request): array
    {
        return match ($request['type'] ?? null) {
            'meta' => ['universe' => [['name' => 'BTC']]],
            'l2Book' => ['levels' => [
                [['px' => '24999.5', 'sz' => '1']],
                [['px' => '25000.5', 'sz' => '1']],
            ]],
            'clearinghouseState' => [
                'withdrawable' => '1000',
                'marginSummary' => ['accountValue' => '1000'],
                'assetPositions' => [],
            ],
            'frontendOpenOrders', 'openOrders' => array_values($this->orders),
            'userFills' => [],
            default => [],
        };
    }

    public function exchange(array $action): array
    {
        return match ($action['type'] ?? null) {
            'order' => $this->placeOrder($action),
            'cancel' => $this->cancelByOrderId($action),
            'cancelByCloid' => $this->cancelByCloid($action),
            'updateLeverage' => $this->okStatus(),
            default => ['status' => 'err', 'response' => ['error' => 'unsupported action']],
        };
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function placeOrder(array $action): array
    {
        $order = $action['orders'][0] ?? [];
        if (!\is_array($order)) {
            return ['status' => 'err', 'response' => ['error' => 'missing order']];
        }

        $cloid = (string)($order['c'] ?? '');
        if (isset($this->cloidIndex[$cloid])) {
            return [
                'status' => 'ok',
                'response' => [
                    'type' => 'order',
                    'data' => ['statuses' => [['error' => 'Duplicate client order ID']]],
                ],
            ];
        }

        $orderId = (string)$this->nextOrderId++;
        $coin = ((int)($order['a'] ?? 0)) === 0 ? 'BTC' : 'UNKNOWN';
        $isTrigger = isset($order['t']['trigger']) && \is_array($order['t']['trigger']);
        $trigger = $isTrigger ? $order['t']['trigger'] : [];
        $limit = isset($order['t']['limit']) && \is_array($order['t']['limit']) ? $order['t']['limit'] : [];

        $this->orders[$orderId] = [
            'coin' => $coin,
            'oid' => (int)$orderId,
            'cloid' => $cloid,
            'side' => ($order['b'] ?? false) === true ? 'B' : 'A',
            'sz' => (string)($order['s'] ?? '0'),
            'origSz' => (string)($order['s'] ?? '0'),
            'limitPx' => (string)($order['p'] ?? '0'),
            'orderType' => $isTrigger ? 'Stop Market' : 'Limit',
            'tif' => (string)($limit['tif'] ?? 'Gtc'),
            'isTrigger' => $isTrigger,
            'reduceOnly' => (bool)($order['r'] ?? false),
            'triggerPx' => $isTrigger ? (string)($trigger['triggerPx'] ?? '') : null,
            'triggerCondition' => $isTrigger && ($trigger['tpsl'] ?? '') === 'tp' ? 'Take Profit' : 'Stop Loss',
            'timestamp' => 1767225600000,
        ];
        $this->cloidIndex[$cloid] = $orderId;

        return $this->restingStatus($orderId);
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function cancelByOrderId(array $action): array
    {
        foreach (($action['cancels'] ?? []) as $cancel) {
            if (!\is_array($cancel)) {
                continue;
            }
            $orderId = (string)($cancel['o'] ?? '');
            if (isset($this->orders[$orderId])) {
                unset($this->cloidIndex[(string)$this->orders[$orderId]['cloid']], $this->orders[$orderId]);
            }
        }

        return $this->okStatus();
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function cancelByCloid(array $action): array
    {
        foreach (($action['cancels'] ?? []) as $cancel) {
            if (!\is_array($cancel)) {
                continue;
            }
            $cloid = (string)($cancel['cloid'] ?? '');
            $orderId = $this->cloidIndex[$cloid] ?? null;
            if ($orderId !== null) {
                unset($this->orders[$orderId], $this->cloidIndex[$cloid]);
            }
        }

        return $this->okStatus();
    }

    /**
     * @return array<string,mixed>
     */
    private function restingStatus(string $orderId): array
    {
        return [
            'status' => 'ok',
            'response' => [
                'type' => 'order',
                'data' => ['statuses' => [['resting' => ['oid' => (int)$orderId]]]],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function okStatus(): array
    {
        return [
            'status' => 'ok',
            'response' => ['type' => 'default', 'data' => ['statuses' => [['success' => true]]]],
        ];
    }
}
