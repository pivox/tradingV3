<?php

declare(strict_types=1);

namespace App\Tests\Application\Runner;

use App\Application\Runner\PostRunProjectionDispatcher;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Indicator\Message\IndicatorSnapshotPersistRequestMessage;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(PostRunProjectionDispatcher::class)]
final class PostRunProjectionDispatcherTest extends TestCase
{
    public function testDispatchesIndicatorPersistenceMessageWithResolvedSymbolsAndTimeframes(): void
    {
        $request = new MtfRunnerRequestDto(
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            profile: 'scalper_micro',
        );

        $mtfValidator = $this->createMock(MtfValidatorInterface::class);
        $mtfValidator->expects(self::once())->method('getListTimeframe')->with('scalper_micro')->willReturn(['5m', '1m']);

        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())->method('now')->willReturn(new \DateTimeImmutable('2025-12-04 08:12:21 Europe/Paris'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $message): bool {
                self::assertInstanceOf(IndicatorSnapshotPersistRequestMessage::class, $message);
                self::assertSame(['BTCUSDT', 'ETHUSDT'], $message->symbols);
                self::assertSame(['5m', '1m'], $message->timeframes);
                self::assertSame('run-123', $message->runId);
                self::assertSame('scalper_micro', $message->profile);
                self::assertSame('2025-12-04 07:12:21', $message->requestedAt);
                self::assertSame('bitmart', $message->exchange);
                self::assertSame('perpetual', $message->marketType);

                return true;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $dispatcher = new PostRunProjectionDispatcher(
            $mtfValidator,
            $messageBus,
            $clock,
            $this->createMock(LoggerInterface::class),
        );

        $dispatcher->dispatch(
            [
                'btcusdt' => ['status' => 'READY'],
                'FINAL' => ['status' => 'completed'],
                'ETHUSDT' => ['status' => 'INVALID'],
            ],
            $request,
            'run-123',
        );
    }

    public function testDoesNotDispatchWhenThereAreNoSymbolResults(): void
    {
        $mtfValidator = $this->createMock(MtfValidatorInterface::class);
        $mtfValidator->expects(self::once())->method('getListTimeframe')->willReturn(['1m']);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $dispatcher = new PostRunProjectionDispatcher(
            $mtfValidator,
            $messageBus,
            $this->createMock(ClockInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $dispatcher->dispatch(['FINAL' => ['status' => 'completed']], new MtfRunnerRequestDto(profile: 'scalper'), 'run-123');
    }
}
