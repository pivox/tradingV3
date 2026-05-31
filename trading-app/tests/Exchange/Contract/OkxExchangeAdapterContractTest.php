<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Contract;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\OkxExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;

#[CoversClass(OkxExchangeAdapter::class)]
#[CoversClass(OkxActionFactory::class)]
#[CoversClass(OkxConfig::class)]
#[CoversClass(OkxInstrumentResolver::class)]
final class OkxExchangeAdapterContractTest extends ExchangeAdapterContractTestCase
{
    private OkxExchangeAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new OkxExchangeAdapter(
            new ContractOkxClient(),
            new OkxInstrumentResolver(),
            new OkxActionFactory(),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                demoTradingEnabled: true,
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
        return Exchange::OKX;
    }

    protected function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    protected function snapshotClientOrderId(string $clientOrderId): string
    {
        return (new OkxActionFactory())->clientOrderId($clientOrderId);
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

final class ContractOkxClient implements OkxRestClientInterface
{
    private int $nextOrderId = 70000;

    private int $nextAlgoId = 80000;

    /** @var array<string,array<string,mixed>> */
    private array $orders = [];

    /** @var array<string,array<string,mixed>> */
    private array $algoOrders = [];

    /** @var array<string,string> */
    private array $clientOrderIndex = [];

    /** @var array<string,string> */
    private array $algoClientOrderIndex = [];

    public function publicGet(string $path, array $query = []): array
    {
        if ($path === '/api/v5/market/books') {
            return ['code' => '0', 'data' => [[
                'bids' => [['24999.5', '1']],
                'asks' => [['25000.5', '1']],
            ]]];
        }

        return ['code' => '0', 'data' => []];
    }

    public function privateGet(string $path, array $query = []): array
    {
        return match ($path) {
            '/api/v5/account/balance' => ['code' => '0', 'data' => [[
                'details' => [[
                    'ccy' => 'USDT',
                    'availEq' => '1000',
                    'eq' => '1000',
                    'upl' => '0',
                ]],
            ]]],
            '/api/v5/account/positions' => ['code' => '0', 'data' => []],
            '/api/v5/trade/orders-pending' => ['code' => '0', 'data' => $this->filterByInstId($this->orders, $query)],
            '/api/v5/trade/orders-algo-pending' => ['code' => '0', 'data' => $this->filterByInstId($this->algoOrders, $query)],
            '/api/v5/trade/fills' => ['code' => '0', 'data' => []],
            default => ['code' => '0', 'data' => []],
        };
    }

    public function privatePost(string $path, array $body = []): array
    {
        return match ($path) {
            '/api/v5/trade/order' => $this->placeOrder($body),
            '/api/v5/trade/order-algo' => $this->placeAlgoOrder($body),
            '/api/v5/trade/cancel-order' => $this->cancelOrder($body),
            '/api/v5/trade/cancel-algos' => $this->cancelAlgoOrder($body),
            '/api/v5/account/set-leverage' => $this->okResponse(),
            default => ['code' => '1', 'data' => [['sCode' => '1', 'sMsg' => 'unsupported path']]],
        };
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function placeOrder(array $body): array
    {
        $clientOrderId = (string)($body['clOrdId'] ?? '');
        if (isset($this->clientOrderIndex[$clientOrderId])) {
            return $this->duplicateClientOrderResponse();
        }
        $instId = (string)($body['instId'] ?? '');
        if ($instId === '') {
            return $this->missingInstrumentResponse();
        }

        $orderId = (string)$this->nextOrderId++;
        $this->orders[$orderId] = [
            'instId' => $instId,
            'ordId' => $orderId,
            'clOrdId' => $clientOrderId,
            'side' => (string)($body['side'] ?? 'buy'),
            'posSide' => (string)($body['posSide'] ?? 'long'),
            'ordType' => (string)($body['ordType'] ?? 'limit'),
            'state' => 'live',
            'sz' => (string)($body['sz'] ?? '0'),
            'accFillSz' => '0',
            'px' => (string)($body['px'] ?? ''),
            'reduceOnly' => (string)($body['reduceOnly'] ?? 'false'),
            'cTime' => '1767225600000',
            'uTime' => '1767225600000',
        ];
        $this->clientOrderIndex[$clientOrderId] = $orderId;

        return $this->orderResponse($orderId, $clientOrderId);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function placeAlgoOrder(array $body): array
    {
        $clientOrderId = (string)($body['algoClOrdId'] ?? '');
        if (isset($this->algoClientOrderIndex[$clientOrderId])) {
            return $this->duplicateClientOrderResponse();
        }
        $instId = (string)($body['instId'] ?? '');
        if ($instId === '') {
            return $this->missingInstrumentResponse();
        }

        $algoId = (string)$this->nextAlgoId++;
        $this->algoOrders[$algoId] = [
            'instId' => $instId,
            'algoId' => $algoId,
            'algoClOrdId' => $clientOrderId,
            'side' => (string)($body['side'] ?? 'sell'),
            'posSide' => (string)($body['posSide'] ?? 'long'),
            'ordType' => (string)($body['ordType'] ?? 'conditional'),
            'state' => 'live',
            'sz' => (string)($body['sz'] ?? '0'),
            'slTriggerPx' => (string)($body['slTriggerPx'] ?? ''),
            'slOrdPx' => (string)($body['slOrdPx'] ?? ''),
            'tpTriggerPx' => (string)($body['tpTriggerPx'] ?? ''),
            'tpOrdPx' => (string)($body['tpOrdPx'] ?? ''),
            'reduceOnly' => (string)($body['reduceOnly'] ?? 'false'),
            'cTime' => '1767225600000',
            'uTime' => '1767225600000',
        ];
        $this->algoClientOrderIndex[$clientOrderId] = $algoId;

        return $this->algoResponse($algoId, $clientOrderId);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function cancelOrder(array $body): array
    {
        $orderId = isset($body['ordId']) ? (string)$body['ordId'] : null;
        if ($orderId === null && isset($body['clOrdId'])) {
            $orderId = $this->clientOrderIndex[(string)$body['clOrdId']] ?? null;
        }
        if ($orderId !== null && isset($this->orders[$orderId])) {
            unset($this->clientOrderIndex[(string)$this->orders[$orderId]['clOrdId']], $this->orders[$orderId]);
        }

        return $this->okResponse();
    }

    /**
     * @param array<int,array<string,mixed>> $body
     * @return array<string,mixed>
     */
    private function cancelAlgoOrder(array $body): array
    {
        $cancel = $body[0] ?? [];
        if (!\is_array($cancel)) {
            return $this->okResponse();
        }

        $algoId = isset($cancel['algoId']) ? (string)$cancel['algoId'] : null;
        if ($algoId === null && isset($cancel['algoClOrdId'])) {
            $algoId = $this->algoClientOrderIndex[(string)$cancel['algoClOrdId']] ?? null;
        }
        if ($algoId !== null && isset($this->algoOrders[$algoId])) {
            unset($this->algoClientOrderIndex[(string)$this->algoOrders[$algoId]['algoClOrdId']], $this->algoOrders[$algoId]);
        }

        return $this->okResponse();
    }

    /**
     * @return array<string,mixed>
     */
    private function orderResponse(string $orderId, string $clientOrderId): array
    {
        return ['code' => '0', 'data' => [[
            'ordId' => $orderId,
            'clOrdId' => $clientOrderId,
            'sCode' => '0',
        ]]];
    }

    /**
     * @return array<string,mixed>
     */
    private function algoResponse(string $algoId, string $clientOrderId): array
    {
        return ['code' => '0', 'data' => [[
            'algoId' => $algoId,
            'algoClOrdId' => $clientOrderId,
            'sCode' => '0',
        ]]];
    }

    /**
     * @return array<string,mixed>
     */
    private function okResponse(): array
    {
        return ['code' => '0', 'data' => [['sCode' => '0']]];
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function filterByInstId(array $rows, array $query): array
    {
        $instId = isset($query['instId']) ? (string)$query['instId'] : '';
        if ($instId === '') {
            return array_values($rows);
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string)($row['instId'] ?? '') === $instId,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicateClientOrderResponse(): array
    {
        return ['code' => '0', 'data' => [[
            'sCode' => '51000',
            'sMsg' => 'duplicate client order id',
        ]]];
    }

    /**
     * @return array<string,mixed>
     */
    private function missingInstrumentResponse(): array
    {
        return ['code' => '0', 'data' => [[
            'sCode' => '51000',
            'sMsg' => 'instId is required',
        ]]];
    }
}
