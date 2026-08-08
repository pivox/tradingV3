<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Message;

use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\ExecutionSelectionDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\MtfValidator\Message\MtfTradingDecisionMessage;
use App\MtfValidator\MessageHandler\MtfTradingDecisionMessageHandler;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

#[CoversClass(MtfTradingDecisionMessage::class)]
#[CoversClass(MtfTradingDecisionMessageHandler::class)]
final class MtfTradingDecisionMessageLineageTest extends TestCase
{
    public function testNativeSerializationPreservesExactCanonicalEnvelopeAcrossRestart(): void
    {
        $message = $this->message('BTCUSDT');

        $serializer = new PhpSerializer();
        $restored = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        self::assertInstanceOf(MtfTradingDecisionMessage::class, $restored);
        $restored->assertCanonicalIdentity();
        self::assertSame($message->identity->toArray(), $restored->identity->toArray());
        self::assertSame($restored->identity->toArray(), $restored->mtfRun->options['lineage_context']);
    }

    public function testDuplicateAndReorderedMessagesKeepTheirOwnSymbolBoundIdentity(): void
    {
        $serializer = new PhpSerializer();
        $btcPayload = $serializer->encode(new Envelope($this->message('BTCUSDT')));
        $ethPayload = $serializer->encode(new Envelope($this->message('ETHUSDT')));

        $deliveries = [$ethPayload, $btcPayload, $btcPayload];
        $symbols = [];
        foreach ($deliveries as $payload) {
            $message = $serializer->decode($payload)->getMessage();
            self::assertInstanceOf(MtfTradingDecisionMessage::class, $message);
            $message->assertCanonicalIdentity();
            $symbols[] = $message->identity->symbol;
        }

        self::assertSame(['ETHUSDT', 'BTCUSDT', 'BTCUSDT'], $symbols);
    }

    public function testConflictingEnvelopeIsUnrecoverableBeforeTradingHandler(): void
    {
        $valid = $this->message('BTCUSDT');
        $conflicting = new MtfTradingDecisionMessage(
            'run-other',
            $valid->mtfRun,
            $valid->result,
            $valid->identity,
        );
        $downstream = (new \ReflectionClass(TradingDecisionHandler::class))->newInstanceWithoutConstructor();
        $handler = new MtfTradingDecisionMessageHandler($downstream, new NullLogger());

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:correlation_run_id');

        $handler($conflicting);
    }

    public function testMissingEmbeddedIdentityIsUnrecoverableBeforeTradingHandler(): void
    {
        $valid = $this->message('BTCUSDT');
        $options = $valid->mtfRun->options;
        unset($options['lineage_context']);
        $run = new MtfRunDto(
            symbol: $valid->mtfRun->symbol,
            profile: $valid->mtfRun->profile,
            mode: $valid->mtfRun->mode,
            now: $valid->mtfRun->now,
            requestId: $valid->mtfRun->requestId,
            dryRun: $valid->mtfRun->dryRun,
            options: $options,
            lineageContext: $valid->identity,
        );
        $downstream = (new \ReflectionClass(TradingDecisionHandler::class))->newInstanceWithoutConstructor();
        $handler = new MtfTradingDecisionMessageHandler($downstream, new NullLogger());

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('canonical_identity_missing:messenger_lineage_context');

        $handler(new MtfTradingDecisionMessage($valid->runId, $run, $valid->result, $valid->identity));
    }

    public function testMalformedPersistedEnvelopeFailsDuringTransportDecode(): void
    {
        $serializer = new PhpSerializer();

        $this->expectException(MessageDecodingFailedException::class);

        $serializer->decode(['body' => 'not-a-serialized-envelope']);
    }

    private function message(string $symbol): MtfTradingDecisionMessage
    {
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $data['symbol'] = $symbol;
        $data['dry_run'] = true;
        $identity = LineageContext::fromArray($data);
        $runId = (string) $identity->correlationRunId;
        $options = [
            'dry_run' => true,
            'exchange' => $identity->exchange,
            'market_type' => $identity->marketType,
            'correlation_run_id' => $runId,
            'orchestration_run_id' => $identity->orchestrationRunId,
            'orchestration_dashboard_id' => $identity->orchestrationDashboardId,
            'orchestration_set_id' => $identity->orchestrationSetId,
            'origin' => $identity->origin,
            'replay_of_run_id' => $identity->replayOfRunId,
            'replay_of_correlation_id' => $identity->replayOfCorrelationId,
            'attempt_number' => $identity->attemptNumber,
            'config_hash' => $identity->configHash,
            'lineage_context' => $identity->toArray(),
        ];
        $run = new MtfRunDto(
            symbol: $symbol,
            profile: (string) $identity->modeId,
            requestId: $runId,
            dryRun: true,
            options: $options,
            lineageContext: $identity,
        );
        $result = new MtfResultDto(
            symbol: $symbol,
            profile: (string) $identity->modeId,
            mode: null,
            evaluatedAt: new \DateTimeImmutable('2026-08-08T00:00:00Z'),
            isTradable: true,
            side: $identity->side,
            executionTimeframe: '1m',
            context: new ContextDecisionDto(true, null, []),
            execution: new ExecutionSelectionDto('1m', strtolower((string) $identity->side), null, []),
        );

        return new MtfTradingDecisionMessage($runId, $run, $result, $identity);
    }
}
