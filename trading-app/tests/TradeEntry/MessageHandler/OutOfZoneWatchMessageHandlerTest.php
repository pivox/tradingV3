<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\MessageHandler;

use App\TradeEntry\Message\OutOfZoneWatchMessage;
use App\TradeEntry\MessageHandler\OutOfZoneWatchMessageHandler;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OutOfZoneWatchMessageHandler::class)]
final class OutOfZoneWatchMessageHandlerTest extends TestCase
{
    public function testPublishesTheWatchWithoutTouchingTheExchange(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $v): string => "'" . $v . "'");
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 1;
        });

        (new OutOfZoneWatchMessageHandler($connection, new NullLogger()))(new OutOfZoneWatchMessage(
            'w1', 'trace-1', 'BTCUSDT', 'long', 24900.0, 25100.0, 300, true, ['symbol' => 'BTCUSDT'],
        ));

        self::assertCount(1, $statements);
        self::assertStringContainsString("pg_notify('out_of_zone_watch'", $statements[0]);
        self::assertStringContainsString('"dry_run":true', $statements[0]);
        self::assertStringContainsString('"execute_payload":{"symbol":"BTCUSDT"}', $statements[0]);
    }

    public function testNotifyFailureIsSwallowed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturn("''");
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('db down'));

        (new OutOfZoneWatchMessageHandler($connection, new NullLogger()))(new OutOfZoneWatchMessage(
            'w1', 'trace-1', 'BTCUSDT', 'long', 1.0, 2.0, 300, true, [],
        ));

        $this->addToAssertionCount(1);
    }
}
