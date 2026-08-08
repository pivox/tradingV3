<?php

declare(strict_types=1);

namespace App\Trading\Lineage\Persistence;

use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait CanonicalLineageProjection
{
    /** @var array<string,mixed>|null */
    #[ORM\Column(name: 'canonical_identity', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $canonicalIdentity = null;

    #[ORM\Column(name: 'orchestration_run_id', length: 255, nullable: true)]
    private ?string $canonicalOrchestrationRunId = null;

    #[ORM\Column(name: 'correlation_run_id', length: 96, nullable: true)]
    private ?string $canonicalCorrelationRunId = null;

    #[ORM\Column(name: 'orchestration_set_id', length: 96, nullable: true)]
    private ?string $canonicalOrchestrationSetId = null;

    #[ORM\Column(name: 'orchestration_dashboard_id', length: 96, nullable: true)]
    private ?string $canonicalOrchestrationDashboardId = null;

    #[ORM\Column(name: 'origin', length: 24, nullable: true)]
    private ?string $canonicalOrigin = null;

    #[ORM\Column(name: 'replay_of_run_id', length: 255, nullable: true)]
    private ?string $canonicalReplayOfRunId = null;

    #[ORM\Column(name: 'replay_of_correlation_id', length: 96, nullable: true)]
    private ?string $canonicalReplayOfCorrelationId = null;

    #[ORM\Column(name: 'attempt_number', type: Types::INTEGER, nullable: true)]
    private ?int $canonicalAttemptNumber = null;

    #[ORM\Column(name: 'environment', length: 32, nullable: true)]
    private ?string $canonicalEnvironment = null;

    #[ORM\Column(name: 'dry_run', type: Types::BOOLEAN, nullable: true)]
    private ?bool $canonicalDryRun = null;

    #[ORM\Column(name: 'mode_id', length: 64, nullable: true)]
    private ?string $canonicalModeId = null;

    #[ORM\Column(name: 'mode_version', length: 32, nullable: true)]
    private ?string $canonicalModeVersion = null;

    #[ORM\Column(name: 'setup_id', length: 160, nullable: true)]
    private ?string $canonicalSetupId = null;

    #[ORM\Column(name: 'setup_version', length: 32, nullable: true)]
    private ?string $canonicalSetupVersion = null;

    #[ORM\Column(name: 'config_hash', length: 128, nullable: true)]
    private ?string $canonicalConfigHash = null;

    #[ORM\Column(name: 'condition_catalog_hash', length: 128, nullable: true)]
    private ?string $canonicalConditionCatalogHash = null;

    #[ORM\Column(name: 'canonical_side', length: 8, nullable: true)]
    private ?string $canonicalSide = null;

    #[ORM\Column(name: 'decision_id', length: 96, nullable: true)]
    private ?string $canonicalDecisionId = null;

    #[ORM\Column(name: 'decision_key', length: 160, nullable: true)]
    private ?string $canonicalDecisionKey = null;

    #[ORM\Column(name: 'intent_id', length: 96, nullable: true)]
    private ?string $canonicalIntentId = null;

    #[ORM\Column(name: 'canonical_order_id', length: 96, nullable: true)]
    private ?string $canonicalOrderId = null;

    #[ORM\Column(name: 'canonical_position_id', length: 96, nullable: true)]
    private ?string $canonicalPositionId = null;

    #[ORM\Column(name: 'canonical_trade_id', length: 96, nullable: true)]
    private ?string $canonicalTradeId = null;

    protected function projectCanonicalLineage(LineageContext $context, string $owner): void
    {
        if (!$context->isModern()) {
            throw new LineageContextException('canonical_identity_missing:' . $owner);
        }
        if ($this->canonicalIdentity === null && $this->hasAnyProjectedCanonicalField()) {
            throw new LineageContextException('canonical_identity_incomplete:' . $owner);
        }
        $payload = $context->toArray();
        if ($this->canonicalIdentity !== null) {
            $this->requireLineageContext();
            if ($this->canonicalIdentity !== $payload) {
                throw new LineageContextException('canonical_identity_mismatch:' . $owner);
            }
        }

        $this->canonicalIdentity = $payload;
        $this->canonicalOrchestrationRunId = $context->orchestrationRunId;
        $this->canonicalCorrelationRunId = $context->correlationRunId;
        $this->canonicalOrchestrationSetId = $context->orchestrationSetId;
        $this->canonicalOrchestrationDashboardId = $context->orchestrationDashboardId;
        $this->canonicalOrigin = $context->origin;
        $this->canonicalReplayOfRunId = $context->replayOfRunId;
        $this->canonicalReplayOfCorrelationId = $context->replayOfCorrelationId;
        $this->canonicalAttemptNumber = $context->attemptNumber;
        $this->canonicalEnvironment = $context->environment;
        $this->canonicalDryRun = $context->dryRun;
        $this->canonicalModeId = $context->modeId;
        $this->canonicalModeVersion = $context->modeVersion;
        $this->canonicalSetupId = $context->setupId;
        $this->canonicalSetupVersion = $context->setupVersion;
        $this->canonicalConfigHash = $context->configHash;
        $this->canonicalConditionCatalogHash = $context->conditionCatalogHash;
        $this->canonicalSide = $context->side;
        $this->canonicalDecisionId = $context->decisionId;
        $this->canonicalDecisionKey = $context->decisionKey;
        $this->canonicalIntentId = $context->intentId;
        $this->canonicalOrderId = $context->orderId;
        $this->canonicalPositionId = $context->positionId;
        $this->canonicalTradeId = $context->tradeId;
    }

    public function requireLineageContext(): LineageContext
    {
        if ($this->canonicalIdentity === null) {
            throw new LineageContextException('canonical_identity_missing:persisted_projection');
        }

        $context = LineageContext::fromArray($this->canonicalIdentity);
        $checks = [
            'orchestration_run_id' => [$this->canonicalOrchestrationRunId, $context->orchestrationRunId],
            'correlation_run_id' => [$this->canonicalCorrelationRunId, $context->correlationRunId],
            'orchestration_set_id' => [$this->canonicalOrchestrationSetId, $context->orchestrationSetId],
            'orchestration_dashboard_id' => [$this->canonicalOrchestrationDashboardId, $context->orchestrationDashboardId],
            'origin' => [$this->canonicalOrigin, $context->origin],
            'replay_of_run_id' => [$this->canonicalReplayOfRunId, $context->replayOfRunId],
            'replay_of_correlation_id' => [$this->canonicalReplayOfCorrelationId, $context->replayOfCorrelationId],
            'attempt_number' => [$this->canonicalAttemptNumber, $context->attemptNumber],
            'environment' => [$this->canonicalEnvironment, $context->environment],
            'dry_run' => [$this->canonicalDryRun, $context->dryRun],
            'mode_id' => [$this->canonicalModeId, $context->modeId],
            'mode_version' => [$this->canonicalModeVersion, $context->modeVersion],
            'setup_id' => [$this->canonicalSetupId, $context->setupId],
            'setup_version' => [$this->canonicalSetupVersion, $context->setupVersion],
            'config_hash' => [$this->canonicalConfigHash, $context->configHash],
            'condition_catalog_hash' => [$this->canonicalConditionCatalogHash, $context->conditionCatalogHash],
            'canonical_side' => [$this->canonicalSide, $context->side],
            'decision_id' => [$this->canonicalDecisionId, $context->decisionId],
            'decision_key' => [$this->canonicalDecisionKey, $context->decisionKey],
            'intent_id' => [$this->canonicalIntentId, $context->intentId],
            'canonical_order_id' => [$this->canonicalOrderId, $context->orderId],
            'canonical_position_id' => [$this->canonicalPositionId, $context->positionId],
            'canonical_trade_id' => [$this->canonicalTradeId, $context->tradeId],
        ];
        foreach ($checks as $field => [$actual, $expected]) {
            if ($actual !== $expected) {
                throw new LineageContextException('canonical_identity_mismatch:persisted_' . $field);
            }
        }

        return $context;
    }

    public function lineageClassification(): string
    {
        if ($this->canonicalIdentity !== null) {
            try {
                $this->requireLineageContext();
                return 'canonical';
            } catch (\Throwable) {
                return 'incomplete';
            }
        }

        return $this->hasAnyProjectedCanonicalField() ? 'incomplete' : 'legacy';
    }

    private function hasAnyProjectedCanonicalField(): bool
    {
        if ($this->hasAdditionalProjectedCanonicalField()) {
            return true;
        }
        foreach ([
            $this->canonicalOrchestrationRunId,
            $this->canonicalCorrelationRunId,
            $this->canonicalOrchestrationSetId,
            $this->canonicalOrchestrationDashboardId,
            $this->canonicalOrigin,
            $this->canonicalReplayOfRunId,
            $this->canonicalReplayOfCorrelationId,
            $this->canonicalAttemptNumber,
            $this->canonicalEnvironment,
            $this->canonicalDryRun,
            $this->canonicalModeId,
            $this->canonicalModeVersion,
            $this->canonicalSetupId,
            $this->canonicalSetupVersion,
            $this->canonicalConfigHash,
            $this->canonicalConditionCatalogHash,
            $this->canonicalSide,
            $this->canonicalDecisionId,
            $this->canonicalDecisionKey,
            $this->canonicalIntentId,
            $this->canonicalOrderId,
            $this->canonicalPositionId,
            $this->canonicalTradeId,
        ] as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    protected function hasAdditionalProjectedCanonicalField(): bool
    {
        return false;
    }

    public function getModeId(): ?string { return $this->canonicalModeId; }
    public function getModeVersion(): ?string { return $this->canonicalModeVersion; }
    public function getSetupId(): ?string { return $this->canonicalSetupId; }
    public function getSetupVersion(): ?string { return $this->canonicalSetupVersion; }
    public function getConfigHash(): ?string { return $this->canonicalConfigHash; }
    public function getConditionCatalogHash(): ?string { return $this->canonicalConditionCatalogHash; }
    public function getCanonicalSide(): ?string { return $this->canonicalSide; }
    public function getCanonicalDecisionId(): ?string { return $this->canonicalDecisionId; }
    public function getCanonicalIntentId(): ?string { return $this->canonicalIntentId; }
    public function getCanonicalOrderId(): ?string { return $this->canonicalOrderId; }
    public function getCanonicalPositionId(): ?string { return $this->canonicalPositionId; }
    public function getCanonicalTradeId(): ?string { return $this->canonicalTradeId; }
}
