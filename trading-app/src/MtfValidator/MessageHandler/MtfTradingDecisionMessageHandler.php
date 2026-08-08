<?php

declare(strict_types=1);

namespace App\MtfValidator\MessageHandler;

use App\MtfValidator\Message\MtfTradingDecisionMessage;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\TradingDecisionHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class MtfTradingDecisionMessageHandler
{
    public function __construct(
        private readonly TradingDecisionHandler $tradingDecisionHandler,
        private readonly LoggerInterface $mtfLogger,
    ) {
    }

    public function __invoke(MtfTradingDecisionMessage $message): void
    {
        try {
            $message->assertCanonicalIdentity();
        } catch (\Throwable $exception) {
            throw new UnrecoverableMessageHandlingException(
                $exception instanceof \App\Trading\Lineage\LineageContextException
                    ? $exception->getMessage()
                    : 'canonical_identity_invalid:messenger_envelope',
                previous: $exception,
            );
        }

        $mtfRunDto = $message->mtfRun;
        $result = $message->result;
        $identity = $message->identity;

        if (!$result->isTradable || $result->executionTimeframe === null || $result->side === null) {
            return;
        }
        $this->mtfLogger->info('[MTF Messenger] Dispatching trading decision', [
            'run_id' => $message->runId,
            'symbol' => $result->symbol,
            'execution_tf' => $result->executionTimeframe,
            'side' => $result->side,
            'dry_run' => $mtfRunDto->dryRun,
            'identity' => $identity->redacted(),
        ]);

        $signalSide = strtoupper($result->side);

        $symbolResult = new SymbolResultDto(
            symbol: $result->symbol,
            status: 'READY',
            executionTf: $result->executionTimeframe,
            blockingTf: null,
            signalSide: $signalSide,
            tradingDecision: null,
            error: null,
            context: [
                'evaluated_at' => $result->evaluatedAt->format(\DateTimeInterface::ATOM),
                'profile' => $result->profile,
                'mode' => $result->mode,
                'context' => $result->context->toArray(),
                'execution' => $result->execution->toArray(),
                'extra' => $result->extra,
                'canonical_identity' => $identity->toArray(),
            ],
            currentPrice: null,
            atr: null,
            validationModeUsed: null,
            tradeEntryModeUsed: null
        );

        $decisionResult = $this->tradingDecisionHandler->handleTradingDecision($symbolResult, $mtfRunDto, $message->runId, $identity);

        if (($decisionResult->tradingDecision['status'] ?? null) === 'rejected'
            && ($decisionResult->tradingDecision['retryable'] ?? null) === false
            && str_starts_with((string) ($decisionResult->tradingDecision['reason'] ?? ''), 'canonical_')) {
            $this->mtfLogger->warning('[MTF Messenger] Canonical policy rejection acknowledged', [
                'run_id' => $message->runId,
                'symbol' => $result->symbol,
                'reason' => $decisionResult->tradingDecision['reason'],
                'blockers' => $decisionResult->tradingDecision['blockers'] ?? [],
                'retryable' => false,
            ]);
            return;
        }

        $this->mtfLogger->info('[MTF Messenger] Trading decision processed', [
            'run_id' => $message->runId,
            'symbol' => $result->symbol,
            'execution_tf' => $result->executionTimeframe,
            'side' => $result->side,
            'dry_run' => $mtfRunDto->dryRun,
            'decision_status' => $decisionResult->tradingDecision['status'] ?? null,
        ]);
    }
}
