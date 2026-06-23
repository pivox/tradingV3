<?php

declare(strict_types=1);

namespace App\MtfValidator\Application;

use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\MtfValidator\Message\MtfTradingDecisionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsAlias(id: TradeDecisionDispatcherInterface::class)]
final class MtfTradeDecisionDispatcher implements TradeDecisionDispatcherInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire(service: 'monolog.logger.mtf')]
        private readonly LoggerInterface $mtfLogger,
        #[Autowire('%app.trade_entry_default_mode%')]
        private readonly string $defaultProfile,
    ) {
    }

    public function dispatchFromResponse(MtfRunRequestDto $request, MtfRunResponseDto $response): void
    {
        foreach ($response->results as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $result = $entry['result'] ?? null;
            if (!$result instanceof MtfResultDto || !$result->isTradable) {
                continue;
            }

            // OBS-003 : si la requête fournit un run_id de corrélation (X-Run-Id côté
            // orchestrateur), il prime sur l'identifiant généré par le validateur, afin que
            // `trade_lifecycle_event.run_id` corresponde au run d'orchestration. Sinon,
            // comportement historique inchangé (run_id du validateur).
            $effectiveRunId = ($request->requestId !== null && $request->requestId !== '')
                ? $request->requestId
                : $response->runId;

            $mtfRun = $this->buildRunDto($request, $effectiveRunId, $result);

            try {
                $this->messageBus->dispatch(new MtfTradingDecisionMessage(
                    $effectiveRunId,
                    $mtfRun,
                    $result,
                ));

                $this->mtfLogger->debug('[MTF Dispatcher] Trading decision dispatched', [
                    'run_id' => $effectiveRunId,
                    'symbol' => $result->symbol,
                    'execution_tf' => $result->executionTimeframe,
                    'side' => $result->side,
                    'dry_run' => $request->dryRun,
                ]);
            } catch (\Throwable $exception) {
                $this->mtfLogger->error('[MTF Dispatcher] Trading decision dispatch failed', [
                    'run_id' => $response->runId,
                    'symbol' => $result->symbol,
                    'execution_tf' => $result->executionTimeframe,
                    'side' => $result->side,
                    'dry_run' => $request->dryRun,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function buildRunDto(MtfRunRequestDto $request, string $runId, MtfResultDto $result): MtfRunDto
    {
        return new MtfRunDto(
            symbol: $result->symbol,
            profile: $result->profile !== '' ? $result->profile : ($request->profile ?? $this->defaultProfile),
            mode: $result->mode ?? $request->mode,
            now: $result->evaluatedAt,
            requestId: $runId,
            dryRun: $request->dryRun,
            options: [
                'dry_run' => $request->dryRun,
                'force_run' => $request->forceRun,
                'current_tf' => $request->currentTf,
                'force_timeframe_check' => $request->forceTimeframeCheck,
                'skip_context' => $request->skipContextValidation,
                'lock_per_symbol' => $request->lockPerSymbol,
                'skip_open_state' => $request->skipOpenStateFilter,
                'user_id' => $request->userId,
                'ip_address' => $request->ipAddress,
                'exchange' => $request->exchange?->value,
                'market_type' => $request->marketType?->value,
                // OBS-003 : lineage transporté jusqu'au TradingDecisionHandler, qui
                // l'inscrit dans l'`extra` de `order_submitted`.
                'correlation_run_id' => $runId,
                'orchestration_run_id' => $request->orchestrationRunId,
                'orchestration_dashboard_id' => $request->dashboardId,
                'orchestration_set_id' => $request->setId,
                'origin' => $request->lineageContext->origin,
                'replay_of_run_id' => $request->lineageContext->replayOfRunId,
                'replay_of_correlation_id' => $request->lineageContext->replayOfCorrelationId,
                'attempt_number' => $request->lineageContext->attemptNumber,
                'config_hash' => $request->lineageContext->configHash,
                'lineage_context' => $request->lineageContext->toArray(),
            ],
        );
    }
}
