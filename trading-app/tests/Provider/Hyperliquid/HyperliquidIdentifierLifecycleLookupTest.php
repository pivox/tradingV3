<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleNormalizer;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleStatus;
use App\Provider\Hyperliquid\HyperliquidIdentifierLifecycleLookup;
use App\Provider\Hyperliquid\HyperliquidIdentifierLifecycleLookupInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidIdentifierLifecycleLookup::class)]
#[CoversClass(HyperliquidIdentifierLifecycleLookupInterface::class)]
final class HyperliquidIdentifierLifecycleLookupTest extends TestCase
{
    private const ACCOUNT = '0x1111111111111111111111111111111111111111';
    private const CLOID = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testLooksUpOidWithExactConfiguredAccountAndNormalizesLifecycle(): void
    {
        $rest = new RecordingInfoClient([[
            'status' => 'order',
            'order' => [
                'status' => 'filled',
                'statusTimestamp' => 1_767_225_601_000,
                'order' => $this->order(oid: 42, cloid: self::CLOID, remaining: '0'),
            ],
        ]]);

        $lifecycle = $this->lookup($rest)->lookup(self::ACCOUNT, '42', '42', self::CLOID);

        self::assertNotNull($lifecycle);
        self::assertSame(HyperliquidLifecycleStatus::FILLED, $lifecycle->status);
        self::assertSame('42', $lifecycle->exchangeOrderId);
        self::assertSame([['type' => 'orderStatus', 'user' => self::ACCOUNT, 'oid' => 42]], $rest->requests);
    }

    public function testLooksUpWireCloidWithoutSymbolOrTimeWindowFields(): void
    {
        $rest = new RecordingInfoClient([[
            'status' => 'order',
            'order' => [
                'status' => 'open',
                'statusTimestamp' => 1_767_225_601_000,
                'order' => $this->order(oid: 43, cloid: self::CLOID, remaining: '1'),
            ],
        ]]);

        $lifecycle = $this->lookup($rest)->lookup(self::ACCOUNT, self::CLOID, null, self::CLOID);

        self::assertNotNull($lifecycle);
        self::assertSame(self::CLOID, $lifecycle->clientOrderId);
        self::assertSame([['type' => 'orderStatus', 'user' => self::ACCOUNT, 'oid' => self::CLOID]], $rest->requests);
        self::assertArrayNotHasKey('coin', $rest->requests[0]);
        self::assertArrayNotHasKey('symbol', $rest->requests[0]);
        self::assertArrayNotHasKey('startTime', $rest->requests[0]);
        self::assertArrayNotHasKey('endTime', $rest->requests[0]);
    }

    public function testReturnsNullOnlyForExactUnknownOidResponse(): void
    {
        $rest = new RecordingInfoClient([['status' => 'unknownOid']]);

        self::assertNull($this->lookup($rest)->lookup(self::ACCOUNT, '42', '42', self::CLOID));
        self::assertCount(1, $rest->requests);
    }

    public function testRejectsAccountOtherThanConfiguredAddressBeforeRead(): void
    {
        $rest = new RecordingInfoClient([]);
        $this->expectExceptionMessage('hyperliquid_identifier_lookup_account_mismatch');

        try {
            $this->lookup($rest)->lookup('0x2222222222222222222222222222222222222222', '42', '42', self::CLOID);
        } finally {
            self::assertSame([], $rest->requests);
        }
    }

    public function testRejectsLifecycleWhoseIdentifierDoesNotMatchRequest(): void
    {
        $rest = new RecordingInfoClient([[
            'status' => 'order',
            'order' => [
                'status' => 'open',
                'order' => $this->order(oid: 99, cloid: self::CLOID, remaining: '1'),
            ],
        ]]);
        $this->expectExceptionMessage('hyperliquid_identifier_lookup_response_mismatch');

        $this->lookup($rest)->lookup(self::ACCOUNT, '42', '42', self::CLOID);
    }

    public function testRejectsOidResponseMissingExpectedCloid(): void
    {
        $order = $this->order(oid: 42, cloid: self::CLOID, remaining: '1');
        unset($order['cloid']);
        $rest = new RecordingInfoClient([[
            'status' => 'order',
            'order' => ['status' => 'open', 'order' => $order],
        ]]);
        $this->expectExceptionMessage('hyperliquid_identifier_lookup_response_mismatch');

        $this->lookup($rest)->lookup(self::ACCOUNT, '42', '42', self::CLOID);
    }

    public function testRejectsCloidResponseWithWrongExpectedOid(): void
    {
        $rest = new RecordingInfoClient([[
            'status' => 'order',
            'order' => [
                'status' => 'open',
                'order' => $this->order(oid: 99, cloid: self::CLOID, remaining: '1'),
            ],
        ]]);
        $this->expectExceptionMessage('hyperliquid_identifier_lookup_response_mismatch');

        $this->lookup($rest)->lookup(self::ACCOUNT, self::CLOID, '42', self::CLOID);
    }

    private function lookup(RecordingInfoClient $rest): HyperliquidIdentifierLifecycleLookup
    {
        return new HyperliquidIdentifierLifecycleLookup(
            $rest,
            new HyperliquidConfig(testnetAccountAddress: self::ACCOUNT),
            new HyperliquidLifecycleNormalizer(new HyperliquidActionFactory()),
        );
    }

    /** @return array<string, mixed> */
    private function order(int $oid, string $cloid, string $remaining): array
    {
        return [
            'coin' => 'BTC',
            'oid' => $oid,
            'cloid' => $cloid,
            'side' => 'B',
            'sz' => $remaining,
            'origSz' => '1',
            'limitPx' => '25000',
            'orderType' => 'Limit',
            'timestamp' => 1_767_225_600_000,
        ];
    }
}

final class RecordingInfoClient implements HyperliquidRestClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @param list<array<mixed>> $responses */
    public function __construct(private array $responses)
    {
    }

    public function info(array $request): array
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? throw new \RuntimeException('unexpected_info_call');
    }

    public function exchange(array $action): array
    {
        throw new \LogicException('exchange_not_expected');
    }
}
