<?php

declare(strict_types=1);

namespace App\MtfValidator\Message;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;

final class MtfTradingDecisionMessage
{
    public function __construct(
        public readonly string $runId,
        public readonly MtfRunDto $mtfRun,
        public readonly MtfResultDto $result,
        public readonly LineageContext $identity,
    ) {
    }

    public function assertCanonicalIdentity(): void
    {
        if (!$this->identity->isModern()) {
            return;
        }
        if ($this->identity->dryRun === null) {
            throw new LineageContextException('canonical_identity_missing:dry_run');
        }
        if ($this->mtfRun->lineageContext === null) {
            throw new LineageContextException('canonical_identity_missing:messenger_run_identity');
        }

        $requiredOptions = [
            'dry_run',
            'exchange',
            'market_type',
            'correlation_run_id',
            'orchestration_run_id',
            'orchestration_dashboard_id',
            'orchestration_set_id',
            'origin',
            'replay_of_run_id',
            'replay_of_correlation_id',
            'attempt_number',
            'config_hash',
            'lineage_context',
        ];
        foreach ($requiredOptions as $field) {
            if (!\array_key_exists($field, $this->mtfRun->options)) {
                throw new LineageContextException('canonical_identity_missing:messenger_' . $field);
            }
        }

        $embedded = $this->mtfRun->options['lineage_context'];
        if (!\is_array($embedded)) {
            throw new LineageContextException('canonical_identity_invalid:messenger_lineage_context');
        }
        $canonical = $this->identity->toArray();
        if ($this->mtfRun->lineageContext->toArray() !== $canonical
            || LineageContext::fromArray($embedded)->toArray() !== $canonical
        ) {
            throw new LineageContextException('canonical_identity_mismatch:messenger_lineage_context');
        }

        $this->identity->assertTradeBoundary(
            $this->result->symbol,
            (string) $this->result->side,
            \is_string($this->mtfRun->options['exchange']) ? $this->mtfRun->options['exchange'] : null,
            \is_string($this->mtfRun->options['market_type']) ? $this->mtfRun->options['market_type'] : null,
            false,
        );

        $checks = [
            'correlation_run_id' => [$this->runId, $this->identity->correlationRunId],
            'mtf_run_request_id' => [$this->mtfRun->requestId, $this->identity->correlationRunId],
            'mtf_run_symbol' => [self::symbol($this->mtfRun->symbol), $this->identity->symbol],
            'result_symbol' => [self::symbol($this->result->symbol), $this->identity->symbol],
            'mtf_run_mode_id' => [$this->mtfRun->profile, $this->identity->modeId],
            'result_mode_id' => [$this->result->profile, $this->identity->modeId],
            'result_side' => [strtoupper((string) $this->result->side), $this->identity->side],
            'mtf_run_dry_run' => [$this->mtfRun->dryRun, $this->identity->dryRun],
            'dry_run' => [$this->mtfRun->options['dry_run'], $this->identity->dryRun],
            'exchange' => [$this->mtfRun->options['exchange'], $this->identity->exchange],
            'market_type' => [$this->mtfRun->options['market_type'], $this->identity->marketType],
            'option_correlation_run_id' => [$this->mtfRun->options['correlation_run_id'], $this->identity->correlationRunId],
            'orchestration_run_id' => [$this->mtfRun->options['orchestration_run_id'], $this->identity->orchestrationRunId],
            'orchestration_dashboard_id' => [$this->mtfRun->options['orchestration_dashboard_id'], $this->identity->orchestrationDashboardId],
            'orchestration_set_id' => [$this->mtfRun->options['orchestration_set_id'], $this->identity->orchestrationSetId],
            'origin' => [$this->mtfRun->options['origin'], $this->identity->origin],
            'replay_of_run_id' => [$this->mtfRun->options['replay_of_run_id'], $this->identity->replayOfRunId],
            'replay_of_correlation_id' => [$this->mtfRun->options['replay_of_correlation_id'], $this->identity->replayOfCorrelationId],
            'attempt_number' => [$this->mtfRun->options['attempt_number'], $this->identity->attemptNumber],
            'config_hash' => [$this->mtfRun->options['config_hash'], $this->identity->configHash],
        ];
        foreach ($checks as $field => [$actual, $expected]) {
            if ($actual !== $expected) {
                throw new LineageContextException('canonical_identity_mismatch:' . $field);
            }
        }
    }

    private static function symbol(string $symbol): string
    {
        return strtoupper(trim($symbol));
    }

}
