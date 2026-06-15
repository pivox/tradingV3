<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Service\ClientOrderIdFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientOrderIdFactory::class)]
final class ClientOrderIdFactoryTest extends TestCase
{
    public function testBuildsDeterministicClientOrderIdFromIdempotencyKey(): void
    {
        $factory = new ClientOrderIdFactory();

        $first = $factory->fromIdempotencyKey('decision:BTCUSDT:long:2025-12-10T10:00:00Z');
        $second = $factory->fromIdempotencyKey('decision:BTCUSDT:long:2025-12-10T10:00:00Z');

        self::assertSame($first, $second);
        self::assertStringStartsWith('CID', $first);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{32}$/', $first);
    }

    public function testRejectsBlankIdempotencyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency key is required to build a client order id.');

        (new ClientOrderIdFactory())->fromIdempotencyKey('  ');
    }
}
