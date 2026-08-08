<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Application;

use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\ExecutionSelectionDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfValidator\Application\MtfTradeDecisionDispatcher;
use App\MtfValidator\Message\MtfTradingDecisionMessage;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(MtfTradeDecisionDispatcher::class)]
final class MtfTradeDecisionDispatcherLineageTest extends TestCase
{
    public function testDispatchesSymbolBoundCopyWithoutMutatingRequestIdentity(): void
    {
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;
                return new Envelope($message);
            },
        );
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        unset($data['symbol']);
        $data['correlation_run_id'] = 'run-1';
        $data['dry_run'] = true;
        $requestIdentity = LineageContext::fromArray($data);
        $request = new MtfRunRequestDto(
            symbols: ['ETHUSDT'],
            dryRun: true,
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            profile: 'scalping',
            requestId: 'run-1',
            orchestrationRunId: 'run-fixture',
            setId: 'set-fixture',
            lineageContext: $requestIdentity,
        );
        $result = new MtfResultDto(
            symbol: 'ethusdt',
            profile: 'scalping',
            mode: null,
            evaluatedAt: new \DateTimeImmutable('2026-08-08T00:00:00Z'),
            isTradable: true,
            side: 'LONG',
            executionTimeframe: '1m',
            context: new ContextDecisionDto(true, null, []),
            execution: new ExecutionSelectionDto('1m', 'long', null, []),
        );
        $response = new MtfRunResponseDto(
            runId: 'validator-run',
            status: 'success',
            executionTimeSeconds: 0.0,
            symbolsRequested: 1,
            symbolsProcessed: 1,
            symbolsSuccessful: 1,
            symbolsFailed: 0,
            symbolsSkipped: 0,
            successRate: 100.0,
            results: [['symbol' => 'ETHUSDT', 'result' => $result]],
            errors: [],
            timestamp: new \DateTimeImmutable('2026-08-08T00:00:00Z'),
        );

        (new MtfTradeDecisionDispatcher(
            $bus,
            $this->createMock(LoggerInterface::class),
        ))->dispatchFromResponse($request, $response);

        self::assertNull($requestIdentity->symbol);
        self::assertInstanceOf(MtfTradingDecisionMessage::class, $dispatched);
        self::assertSame('ETHUSDT', $dispatched->identity->symbol);
        self::assertSame($dispatched->identity, $dispatched->mtfRun->lineageContext);
        self::assertSame(
            $dispatched->identity->toArray(),
            $dispatched->mtfRun->options['lineage_context'],
        );
        $dispatched->assertCanonicalIdentity();
    }
}
