<?php

declare(strict_types=1);

namespace App\Trading\Lineage;

/**
 * Contexte de lineage persistant et sérialisable.
 *
 * Le contexte est construit en bordure HTTP/CLI, puis transporté tel quel dans le
 * runner, Messenger, les intents et les événements. Les champs structurants restent
 * typés ici; le JSON `extra` ne sert que de snapshot de compatibilité.
 */
final readonly class LineageContext
{
    public const CONTRACT_LEGACY = 'legacy';
    public const CONTRACT_MODERN = 'modern';
    public const ORIGIN_ORCHESTRATOR = 'orchestrator';
    public const ORIGIN_LEGACY = 'legacy';
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_REPLAY = 'replay';

    /** @var array<string, array{mode:string,side:string,version:string}> */
    private const MODERN_SETUPS = [
        'day_trading.trend_continuation.long' => ['mode' => 'day_trading', 'side' => 'LONG', 'version' => '1.0.0'],
        'day_trading.trend_continuation.short' => ['mode' => 'day_trading', 'side' => 'SHORT', 'version' => '1.0.0'],
        'scalping.trend_continuation.long' => ['mode' => 'scalping', 'side' => 'LONG', 'version' => '1.0.0'],
        'scalping.pullback.long' => ['mode' => 'scalping', 'side' => 'LONG', 'version' => '1.0.0'],
        'scalping.trend_momentum.short' => ['mode' => 'scalping', 'side' => 'SHORT', 'version' => '1.0.0'],
        'micro_scalping.momentum_ofi.long' => ['mode' => 'micro_scalping', 'side' => 'LONG', 'version' => '1.0.0'],
        'micro_scalping.momentum_ofi.short' => ['mode' => 'micro_scalping', 'side' => 'SHORT', 'version' => '1.0.0'],
    ];

    /** @var string[] */
    private const TRADE_STAGE_ID_FIELDS = [
        'trading_decision_id',
        'order_intent_id',
        'internal_trade_id',
        'internal_position_id',
        'client_order_id',
        'exchange_order_id',
        'exchange_position_id',
        'decision_id',
        'decision_key',
        'intent_id',
        'order_id',
        'position_id',
        'trade_id',
    ];

    public string $contractKind;

    public function __construct(
        public string $origin,
        public ?string $orchestrationRunId = null,
        public ?string $correlationRunId = null,
        public ?string $orchestrationSetId = null,
        public ?string $orchestrationDashboardId = null,
        public ?string $mtfProfile = null,
        public ?string $exchange = null,
        public ?string $environment = null,
        public ?string $marketType = null,
        public ?string $symbol = null,
        public ?string $tradingDecisionId = null,
        public ?string $orderIntentId = null,
        public ?string $internalTradeId = null,
        public ?string $internalPositionId = null,
        public ?string $clientOrderId = null,
        public ?string $exchangeOrderId = null,
        public ?string $exchangePositionId = null,
        public ?string $replayOfRunId = null,
        public ?string $replayOfCorrelationId = null,
        public int $attemptNumber = 1,
        public ?string $configHash = null,
        public ?bool $dryRun = null,
        public ?string $modeId = null,
        public ?string $modeVersion = null,
        public ?string $setupId = null,
        public ?string $setupVersion = null,
        public ?string $conditionCatalogHash = null,
        public ?string $side = null,
        public ?string $decisionId = null,
        public ?string $decisionKey = null,
        public ?string $intentId = null,
        public ?string $orderId = null,
        public ?string $positionId = null,
        public ?string $tradeId = null,
        public ?string $effectiveConfigReference = null,
        /** Validated immutable effective configuration supplied by the orchestrator. */
        public ?CanonicalEffectiveConfigSnapshot $effectiveConfigSnapshot = null,
        ?string $contractKind = null,
    ) {
        $this->contractKind = $contractKind ?? (($modeId !== null || $setupId !== null) ? self::CONTRACT_MODERN : self::CONTRACT_LEGACY);
        if (!\in_array($this->contractKind, [self::CONTRACT_LEGACY, self::CONTRACT_MODERN], true)) {
            throw new LineageContextException('canonical_identity_invalid:contract_kind');
        }
        if (($modeId !== null || $setupId !== null) && $this->contractKind !== self::CONTRACT_MODERN) {
            throw new LineageContextException('canonical_identity_mismatch:contract_kind');
        }
        if (!\in_array($origin, [self::ORIGIN_ORCHESTRATOR, self::ORIGIN_LEGACY, self::ORIGIN_MANUAL, self::ORIGIN_REPLAY], true)) {
            throw new LineageContextException(sprintf('origin "%s" non supporte.', $origin));
        }
        if ($attemptNumber < 1) {
            throw new LineageContextException('attempt_number doit etre >= 1.');
        }
        if ($origin === self::ORIGIN_REPLAY && $replayOfRunId === null && $replayOfCorrelationId === null) {
            throw new LineageContextException('Un replay doit référencer le run ou la correlation d origine.');
        }
        if ($side !== null && !\in_array($side, ['LONG', 'SHORT'], true)) {
            throw new LineageContextException('canonical_identity_invalid:side');
        }
        if ($this->isModern()) {
            self::assertCanonicalPayload([
                'orchestration_run_id' => $orchestrationRunId,
                'correlation_run_id' => $correlationRunId,
                'orchestration_set_id' => $orchestrationSetId,
                'orchestration_dashboard_id' => $orchestrationDashboardId,
                'mode_id' => $modeId,
                'mode_version' => $modeVersion,
                'setup_id' => $setupId,
                'setup_version' => $setupVersion,
                'config_hash' => $configHash,
                'condition_catalog_hash' => $conditionCatalogHash,
                'side' => $side,
                'exchange' => $exchange,
                'environment' => $environment,
                'market_type' => $marketType,
                'symbol' => $symbol,
                'trading_decision_id' => $tradingDecisionId,
                'order_intent_id' => $orderIntentId,
                'internal_trade_id' => $internalTradeId,
                'internal_position_id' => $internalPositionId,
                'client_order_id' => $clientOrderId,
                'exchange_order_id' => $exchangeOrderId,
                'exchange_position_id' => $exchangePositionId,
                'decision_id' => $decisionId ?? $tradingDecisionId,
                'decision_key' => $decisionKey,
                'intent_id' => $intentId ?? $orderIntentId,
                'order_id' => $orderId ?? $exchangeOrderId,
                'position_id' => $positionId ?? $exchangePositionId,
                'trade_id' => $tradeId ?? $internalTradeId,
                'effective_config_reference' => $effectiveConfigReference,
                'effective_config_snapshot' => $effectiveConfigSnapshot,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromOrchestratorPayload(array $payload): self
    {
        $canonical = self::hasCanonicalIdentity($payload);
        $contractKind = self::string($payload['contract_kind'] ?? null) ?? ($canonical ? self::CONTRACT_MODERN : self::CONTRACT_LEGACY);
        if ($canonical && $contractKind !== self::CONTRACT_MODERN) {
            throw new LineageContextException('canonical_identity_mismatch:contract_kind');
        }
        $runId = $canonical
            ? self::string($payload['orchestration_run_id'] ?? null)
            : self::firstString($payload, ['run_id', 'original_run_id', 'orchestration_run_id']);
        $correlationRunId = self::string($payload['correlation_run_id'] ?? null) ?? $runId;
        $setId = self::sameAlias($payload, 'set_id', 'orchestration_set_id');
        $dashboardId = self::sameAlias($payload, 'dashboard_id', 'orchestration_dashboard_id');
        $profile = self::sameAlias($payload, 'profile', 'mtf_profile');
        $exchange = self::normalizeExchange(self::firstString($payload, ['exchange', 'cex']));
        $marketType = self::normalizeMarketType(self::firstString($payload, ['market_type', 'type_contract']));
        $environment = self::string($payload['environment'] ?? null)
            ?? self::snapshotRequestString($payload, 'environment');
        if ($environment !== null) {
            $payload['environment'] = $environment;
        }
        $origin = self::string($payload['origin'] ?? null) ?? self::ORIGIN_ORCHESTRATOR;
        if ($canonical) {
            self::assertCanonicalPayload($payload);
        }

        return new self(
            origin: strtolower($origin),
            orchestrationRunId: $runId,
            correlationRunId: $correlationRunId,
            orchestrationSetId: $setId,
            orchestrationDashboardId: $dashboardId,
            mtfProfile: $profile,
            exchange: $exchange,
            environment: $environment,
            marketType: $marketType,
            symbol: self::normalizeSymbol(self::string($payload['symbol'] ?? null)),
            tradingDecisionId: self::string($payload['trading_decision_id'] ?? null),
            orderIntentId: self::string($payload['order_intent_id'] ?? null),
            internalTradeId: self::string($payload['internal_trade_id'] ?? null),
            internalPositionId: self::string($payload['internal_position_id'] ?? null),
            clientOrderId: self::string($payload['client_order_id'] ?? null),
            exchangeOrderId: self::string($payload['exchange_order_id'] ?? null),
            exchangePositionId: self::string($payload['exchange_position_id'] ?? $payload['position_id'] ?? null),
            replayOfRunId: self::string($payload['replay_of_run_id'] ?? null),
            replayOfCorrelationId: self::string($payload['replay_of_correlation_id'] ?? null),
            attemptNumber: self::positiveInt($payload['attempt_number'] ?? null),
            configHash: self::string($payload['config_hash'] ?? $payload['config_effective_version'] ?? null),
            dryRun: isset($payload['dry_run']) ? filter_var($payload['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
            modeId: self::string($payload['mode_id'] ?? null),
            modeVersion: self::string($payload['mode_version'] ?? null),
            setupId: self::string($payload['setup_id'] ?? null),
            setupVersion: self::string($payload['setup_version'] ?? null),
            conditionCatalogHash: self::string($payload['condition_catalog_hash'] ?? null),
            side: self::normalizeSide(self::string($payload['side'] ?? null)),
            decisionId: self::string($payload['decision_id'] ?? $payload['trading_decision_id'] ?? null),
            decisionKey: self::string($payload['decision_key'] ?? null),
            intentId: self::string($payload['intent_id'] ?? $payload['order_intent_id'] ?? null),
            orderId: self::string($payload['order_id'] ?? $payload['exchange_order_id'] ?? null),
            positionId: self::string($payload['position_id'] ?? $payload['exchange_position_id'] ?? null),
            tradeId: self::string($payload['trade_id'] ?? $payload['internal_trade_id'] ?? null),
            effectiveConfigReference: self::string($payload['effective_config_reference'] ?? null),
            effectiveConfigSnapshot: self::effectiveConfigSnapshot($payload),
            contractKind: $contractKind,
        );
    }

    public static function legacy(
        ?string $symbol = null,
        ?string $exchange = null,
        ?string $marketType = null,
        ?string $mtfProfile = null,
    ): self {
        return new self(
            origin: self::ORIGIN_LEGACY,
            mtfProfile: self::string($mtfProfile),
            exchange: self::normalizeExchange($exchange),
            marketType: self::normalizeMarketType($marketType),
            symbol: self::normalizeSymbol($symbol),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $environment = self::string($data['environment'] ?? null) ?? self::snapshotRequestString($data, 'environment');
        if ($environment !== null) {
            $data['environment'] = $environment;
        }
        $canonical = self::hasCanonicalIdentity($data);
        $contractKind = self::string($data['contract_kind'] ?? null) ?? ($canonical ? self::CONTRACT_MODERN : self::CONTRACT_LEGACY);
        if ($canonical && $contractKind !== self::CONTRACT_MODERN) {
            throw new LineageContextException('canonical_identity_mismatch:contract_kind');
        }
        if ($canonical) {
            self::assertCanonicalPayload($data);
        }

        return new self(
            origin: self::string($data['origin'] ?? null) ?? self::ORIGIN_LEGACY,
            orchestrationRunId: self::string($data['orchestration_run_id'] ?? null),
            correlationRunId: self::string($data['correlation_run_id'] ?? null),
            orchestrationSetId: self::string($data['orchestration_set_id'] ?? null),
            orchestrationDashboardId: self::string($data['orchestration_dashboard_id'] ?? null),
            mtfProfile: self::string($data['mtf_profile'] ?? $data['profile'] ?? null),
            exchange: self::normalizeExchange(self::string($data['exchange'] ?? null)),
            environment: $environment,
            marketType: self::normalizeMarketType(self::string($data['market_type'] ?? null)),
            symbol: self::normalizeSymbol(self::string($data['symbol'] ?? null)),
            tradingDecisionId: self::string($data['trading_decision_id'] ?? null),
            orderIntentId: self::string($data['order_intent_id'] ?? null),
            internalTradeId: self::string($data['internal_trade_id'] ?? null),
            internalPositionId: self::string($data['internal_position_id'] ?? null),
            clientOrderId: self::string($data['client_order_id'] ?? null),
            exchangeOrderId: self::string($data['exchange_order_id'] ?? null),
            exchangePositionId: self::string($data['exchange_position_id'] ?? null),
            replayOfRunId: self::string($data['replay_of_run_id'] ?? null),
            replayOfCorrelationId: self::string($data['replay_of_correlation_id'] ?? null),
            attemptNumber: self::positiveInt($data['attempt_number'] ?? null),
            configHash: self::string($data['config_hash'] ?? null),
            dryRun: \array_key_exists('dry_run', $data) ? (bool) $data['dry_run'] : null,
            modeId: self::string($data['mode_id'] ?? null),
            modeVersion: self::string($data['mode_version'] ?? null),
            setupId: self::string($data['setup_id'] ?? null),
            setupVersion: self::string($data['setup_version'] ?? null),
            conditionCatalogHash: self::string($data['condition_catalog_hash'] ?? null),
            side: self::normalizeSide(self::string($data['side'] ?? null)),
            decisionId: self::string($data['decision_id'] ?? null),
            decisionKey: self::string($data['decision_key'] ?? null),
            intentId: self::string($data['intent_id'] ?? null),
            orderId: self::string($data['order_id'] ?? null),
            positionId: self::string($data['position_id'] ?? null),
            tradeId: self::string($data['trade_id'] ?? null),
            effectiveConfigReference: self::string($data['effective_config_reference'] ?? null),
            effectiveConfigSnapshot: self::effectiveConfigSnapshot($data),
            contractKind: $contractKind,
        );
    }

    public function asReplay(string $newRunId, ?string $sourceRunId, ?string $sourceCorrelationId, int $attemptNumber): self
    {
        return new self(
            origin: self::ORIGIN_REPLAY,
            orchestrationRunId: self::string($newRunId),
            correlationRunId: self::string($newRunId),
            orchestrationSetId: $this->orchestrationSetId,
            orchestrationDashboardId: $this->orchestrationDashboardId,
            mtfProfile: $this->mtfProfile,
            exchange: $this->exchange,
            environment: $this->environment,
            marketType: $this->marketType,
            symbol: $this->symbol,
            tradingDecisionId: $this->tradingDecisionId,
            orderIntentId: $this->orderIntentId,
            internalTradeId: $this->internalTradeId,
            internalPositionId: $this->internalPositionId,
            clientOrderId: $this->clientOrderId,
            exchangeOrderId: $this->exchangeOrderId,
            exchangePositionId: $this->exchangePositionId,
            replayOfRunId: $sourceRunId,
            replayOfCorrelationId: $sourceCorrelationId,
            attemptNumber: $attemptNumber,
            configHash: $this->configHash,
            dryRun: $this->dryRun,
            modeId: $this->modeId,
            modeVersion: $this->modeVersion,
            setupId: $this->setupId,
            setupVersion: $this->setupVersion,
            conditionCatalogHash: $this->conditionCatalogHash,
            side: $this->side,
            decisionId: $this->decisionId,
            decisionKey: $this->decisionKey,
            intentId: $this->intentId,
            orderId: $this->orderId,
            positionId: $this->positionId,
            tradeId: $this->tradeId,
            effectiveConfigReference: $this->effectiveConfigReference,
            effectiveConfigSnapshot: $this->effectiveConfigSnapshot,
            contractKind: $this->contractKind,
        );
    }

    public function withDecision(string $decisionId, string $decisionKey): self
    {
        return $this->copy(decisionId: $decisionId, decisionKey: $decisionKey);
    }

    public function withSymbol(string $symbol): self
    {
        $symbol = strtoupper(trim($symbol));
        if (preg_match('/\A[A-Z0-9]{2,32}\z/D', $symbol) !== 1) {
            throw new LineageContextException('canonical_identity_invalid:symbol');
        }
        if ($this->symbol !== null && $this->symbol !== $symbol) {
            throw new LineageContextException('canonical_identity_mismatch:symbol');
        }

        $data = $this->toArray();
        $data['symbol'] = $symbol;

        return self::fromArray($data);
    }

    public function withIntent(string $intentId): self
    {
        return $this->copy(intentId: $intentId);
    }

    public function withExecution(string $orderId, ?string $positionId, ?string $tradeId): self
    {
        return $this->copy(orderId: $orderId, positionId: $positionId, tradeId: $tradeId);
    }

    public function assertTradeBoundary(
        string $symbol,
        string $side,
        ?string $exchange = null,
        ?string $marketType = null,
        bool $requireDecision = true,
    ): self {
        if (!$this->isModern() || $this->modeId === null || $this->setupId === null) {
            throw new LineageContextException('canonical_identity_missing:modern_contract');
        }
        if ($this->symbol === null) {
            throw new LineageContextException('canonical_identity_missing:symbol');
        }
        $checks = [
            'symbol' => [self::normalizeSymbol($symbol), $this->symbol],
            'side' => [self::normalizeSide($side), $this->side],
            'exchange' => [$exchange === null ? null : self::normalizeExchange($exchange), $this->exchange],
            'market_type' => [$marketType === null ? null : self::normalizeMarketType($marketType), $this->marketType],
        ];
        foreach ($checks as $field => [$actual, $expected]) {
            if ($actual !== null && $actual !== $expected) {
                throw new LineageContextException('canonical_identity_mismatch:' . $field);
            }
        }
        if ($requireDecision) {
            foreach (['decisionId' => $this->decisionId, 'decisionKey' => $this->decisionKey] as $field => $value) {
                if ($value === null) {
                    throw new LineageContextException('canonical_identity_missing:' . $field);
                }
            }
        }

        return $this;
    }

    public function assertExecutableTradeContract(): self
    {
        $snapshot = $this->effectiveConfigSnapshot?->toArray();
        if ($snapshot === null) {
            throw new LineageContextException('canonical_identity_missing:effective_config_snapshot');
        }
        if (!$this->effectiveConfigSnapshot?->executable()) {
            throw new LineageContextException('canonical_contract_not_executable');
        }
        foreach (['config_hash' => $this->configHash, 'condition_catalog_hash' => $this->conditionCatalogHash] as $field => $expected) {
            if (($snapshot[$field] ?? null) !== $expected) {
                throw new LineageContextException('canonical_identity_mismatch:' . $field);
            }
        }
        $request = $snapshot['request'] ?? null;
        if (!\is_array($request)) {
            throw new LineageContextException('canonical_identity_missing:effective_config_request');
        }
        foreach ([
            'mode_id' => $this->modeId,
            'mode_version' => $this->modeVersion,
            'setup_id' => $this->setupId,
            'setup_version' => $this->setupVersion,
            'exchange' => $this->exchange,
            'environment' => $this->environment,
            'side' => strtolower((string) $this->side),
        ] as $field => $expected) {
            if (($request[$field] ?? null) !== $expected) {
                throw new LineageContextException('canonical_identity_mismatch:' . $field);
            }
        }

        return $this;
    }

    public function isModern(): bool
    {
        return $this->contractKind === self::CONTRACT_MODERN;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'origin' => $this->origin,
            'contract_kind' => $this->contractKind,
            'orchestration_run_id' => $this->orchestrationRunId,
            'correlation_run_id' => $this->correlationRunId,
            'orchestration_set_id' => $this->orchestrationSetId,
            'orchestration_dashboard_id' => $this->orchestrationDashboardId,
            'mtf_profile' => $this->modeId === null ? $this->mtfProfile : null,
            'exchange' => $this->exchange,
            'market_type' => $this->marketType,
            'symbol' => $this->symbol,
            'trading_decision_id' => $this->tradingDecisionId,
            'order_intent_id' => $this->orderIntentId,
            'internal_trade_id' => $this->internalTradeId,
            'internal_position_id' => $this->internalPositionId,
            'client_order_id' => $this->clientOrderId,
            'exchange_order_id' => $this->exchangeOrderId,
            'exchange_position_id' => $this->exchangePositionId,
            'replay_of_run_id' => $this->replayOfRunId,
            'replay_of_correlation_id' => $this->replayOfCorrelationId,
            'attempt_number' => $this->attemptNumber,
            'config_hash' => $this->configHash,
            'dry_run' => $this->dryRun,
            'mode_id' => $this->modeId,
            'mode_version' => $this->modeVersion,
            'setup_id' => $this->setupId,
            'setup_version' => $this->setupVersion,
            'condition_catalog_hash' => $this->conditionCatalogHash,
            'side' => $this->side,
            'decision_id' => $this->decisionId,
            'decision_key' => $this->decisionKey,
            'intent_id' => $this->intentId,
            'order_id' => $this->orderId,
            'position_id' => $this->positionId,
            'trade_id' => $this->tradeId,
            'effective_config_reference' => $this->effectiveConfigReference,
            'effective_config_snapshot' => $this->effectiveConfigSnapshot?->toArray(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function copy(
        ?string $decisionId = null,
        ?string $decisionKey = null,
        ?string $intentId = null,
        ?string $orderId = null,
        ?string $positionId = null,
        ?string $tradeId = null,
    ): self {
        $data = $this->toArray();
        foreach (compact('decisionId', 'decisionKey', 'intentId', 'orderId', 'positionId', 'tradeId') as $property => $value) {
            if ($value !== null) {
                $value = self::requiredStageId($property, $value);
                if ($this->{$property} !== null && $this->{$property} !== $value) {
                    throw new LineageContextException('canonical_identity_mismatch:' . $property);
                }
                $data[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property))] = $value;
            }
        }

        return self::fromArray($data);
    }

    /** @param array<string,mixed> $payload */
    private static function hasCanonicalIdentity(array $payload): bool
    {
        return self::string($payload['mode_id'] ?? null) !== null || self::string($payload['setup_id'] ?? null) !== null;
    }

    /** @param array<string,mixed> $payload */
    private static function assertCanonicalPayload(array $payload): void
    {
        foreach (['orchestration_run_id', 'orchestration_set_id', 'mode_id', 'mode_version', 'setup_id', 'setup_version', 'config_hash', 'condition_catalog_hash', 'side', 'exchange', 'market_type'] as $field) {
            if (self::string($payload[$field] ?? null) === null) {
                throw new LineageContextException('canonical_identity_missing:' . $field);
            }
        }
        $modeId = self::string($payload['mode_id'] ?? null);
        if (!\in_array($modeId, ['day_trading', 'scalping', 'micro_scalping'], true)) {
            throw new LineageContextException('canonical_identity_invalid:mode_id');
        }
        $setupId = self::string($payload['setup_id'] ?? null);
        if ($setupId === null || !isset(self::MODERN_SETUPS[$setupId])) {
            throw new LineageContextException('canonical_identity_invalid:setup_id');
        }
        $setup = self::MODERN_SETUPS[$setupId];
        if ($modeId !== $setup['mode']) {
            throw new LineageContextException('canonical_identity_mismatch:mode_id');
        }
        if (self::normalizeSide(self::string($payload['side'] ?? null)) !== $setup['side']) {
            throw new LineageContextException('canonical_identity_mismatch:side');
        }
        if (($payload['mode_version'] ?? null) !== '1.0.0') {
            throw new LineageContextException('canonical_identity_invalid:mode_version');
        }
        if (($payload['setup_version'] ?? null) !== $setup['version']) {
            throw new LineageContextException('canonical_identity_invalid:setup_version');
        }
        foreach (['config_hash', 'condition_catalog_hash'] as $hashField) {
            if (!\is_string($payload[$hashField] ?? null) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $payload[$hashField]) !== 1) {
                throw new LineageContextException('canonical_identity_invalid:' . $hashField);
            }
        }
        if (!\in_array($payload['exchange'] ?? null, ['fake', 'okx', 'hyperliquid'], true)) {
            throw new LineageContextException('canonical_identity_invalid:exchange');
        }
        if (!\in_array($payload['market_type'] ?? null, ['perpetual', 'spot'], true)) {
            throw new LineageContextException('canonical_identity_invalid:market_type');
        }
        if (($payload['symbol'] ?? null) !== null && (!\is_string($payload['symbol']) || preg_match('/\A[A-Z0-9]{2,32}\z/D', $payload['symbol']) !== 1)) {
            throw new LineageContextException('canonical_identity_invalid:symbol');
        }
        if (($payload['symbol'] ?? null) === null) {
            foreach (self::TRADE_STAGE_ID_FIELDS as $field) {
                if (($payload[$field] ?? null) !== null) {
                    throw new LineageContextException('canonical_identity_missing:symbol');
                }
            }
        }
        foreach (['orchestration_run_id' => 255, 'correlation_run_id' => 96, 'orchestration_set_id' => 96, 'orchestration_dashboard_id' => 96] as $idField => $max) {
            if (isset($payload[$idField]) && $payload[$idField] !== null && !self::isSafeId($payload[$idField], $max)) {
                throw new LineageContextException('canonical_identity_invalid:' . $idField);
            }
        }
        if (isset($payload['decision_id']) && $payload['decision_id'] !== null && (!\is_string($payload['decision_id']) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $payload['decision_id']) !== 1)) {
            throw new LineageContextException('canonical_identity_invalid:decision_id');
        }
        foreach (['decision_key' => 160, 'intent_id' => 96, 'order_id' => 96, 'position_id' => 96, 'trade_id' => 96] as $idField => $max) {
            if (isset($payload[$idField]) && $payload[$idField] !== null && !self::isSafeId($payload[$idField], $max)) {
                throw new LineageContextException('canonical_identity_invalid:' . $idField);
            }
        }
        if (self::string($payload['effective_config_reference'] ?? null) === null) {
            throw new LineageContextException('canonical_identity_missing:effective_config_reference');
        }
        $effectiveSnapshot = $payload['effective_config_snapshot'] ?? null;
        if (!\is_array($effectiveSnapshot) && !$effectiveSnapshot instanceof CanonicalEffectiveConfigSnapshot) {
            throw new LineageContextException('canonical_identity_missing:effective_config_snapshot');
        }
        if (self::string($payload['environment'] ?? null) === null) {
            throw new LineageContextException('canonical_identity_missing:environment');
        }

        self::assertSameValues($payload, 'side', ['context_side', 'execution_side'], true);
        self::assertSameValues($payload, 'mode_id', ['requested_mode_id', 'resolved_mode_id', 'validated_mode_id', 'planned_mode_id', 'executed_mode_id', 'analyzed_mode_id']);
        self::assertSameValues($payload, 'mode_version', ['requested_mode_version', 'resolved_mode_version', 'validated_mode_version']);
        self::assertSameValues($payload, 'setup_version', ['validated_setup_version', 'planned_setup_version', 'executed_setup_version']);
        self::assertSameValues($payload, 'config_hash', ['effective_config_hash', 'validated_config_hash', 'planned_config_hash', 'executed_config_hash']);
        self::assertSameValues($payload, 'condition_catalog_hash', ['validated_condition_catalog_hash']);
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[] $others
     */
    private static function assertSameValues(array $payload, string $canonical, array $others, bool $normalizeSide = false): void
    {
        $expected = self::string($payload[$canonical] ?? null);
        if ($normalizeSide) {
            $expected = self::normalizeSide($expected);
        }
        foreach ($others as $other) {
            $actual = self::string($payload[$other] ?? null);
            if ($actual === null) {
                continue;
            }
            if ($normalizeSide) {
                $actual = self::normalizeSide($actual);
            }
            if ($actual !== $expected) {
                throw new LineageContextException('canonical_identity_mismatch:' . $canonical);
            }
        }
    }

    private static function requiredStageId(string $field, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new LineageContextException('canonical_identity_missing:' . $field);
        }

        return $value;
    }

    private static function isSafeId(mixed $value, int $maxLength): bool
    {
        return \is_string($value)
            && strlen($value) <= $maxLength
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $value) === 1;
    }

    private static function normalizeSide(?string $side): ?string
    {
        return $side === null ? null : strtoupper($side);
    }

    /** @param array<string,mixed> $payload */
    private static function effectiveConfigSnapshot(array $payload): ?CanonicalEffectiveConfigSnapshot
    {
        $snapshot = $payload['effective_config_snapshot'] ?? null;
        if ($snapshot === null) { return null; }
        if (!\is_array($snapshot)) {
            throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot');
        }
        return CanonicalEffectiveConfigSnapshot::fromArray($snapshot, [
            'mode_id' => self::string($payload['mode_id'] ?? null),
            'mode_version' => self::string($payload['mode_version'] ?? null),
            'setup_id' => self::string($payload['setup_id'] ?? null),
            'setup_version' => self::string($payload['setup_version'] ?? null),
            'exchange' => self::normalizeExchange(self::string($payload['exchange'] ?? null)),
            'environment' => self::string($payload['environment'] ?? null) ?? self::snapshotRequestString($payload, 'environment'),
            'side' => self::normalizeSide(self::string($payload['side'] ?? null)),
            'config_hash' => self::string($payload['config_hash'] ?? null),
            'condition_catalog_hash' => self::string($payload['condition_catalog_hash'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private static function snapshotRequestString(array $payload, string $field): ?string
    {
        $snapshot = $payload['effective_config_snapshot'] ?? null;
        if ($snapshot instanceof CanonicalEffectiveConfigSnapshot) {
            $snapshot = $snapshot->toArray();
        }
        return \is_array($snapshot) && \is_array($snapshot['request'] ?? null)
            ? self::string($snapshot['request'][$field] ?? null)
            : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function redacted(): array
    {
        $redacted = $this->toArray();
        unset($redacted['effective_config_snapshot']);

        return $redacted;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function sameAlias(array $payload, string $short, string $long): ?string
    {
        $a = self::string($payload[$short] ?? null);
        $b = self::string($payload[$long] ?? null);
        if ($a !== null && $b !== null && strcasecmp($a, $b) !== 0) {
            throw new LineageContextException(sprintf('%s et %s sont contradictoires.', $short, $long));
        }

        return $a ?? $b;
    }

    /**
     * @param array<string,mixed> $payload
     * @param string[] $keys
     */
    private static function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::string($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function positiveInt(mixed $value): int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return 1;
    }

    private static function normalizeSymbol(?string $symbol): ?string
    {
        return $symbol !== null ? strtoupper($symbol) : null;
    }

    private static function normalizeExchange(?string $exchange): ?string
    {
        return $exchange !== null ? strtolower($exchange) : null;
    }

    private static function normalizeMarketType(?string $marketType): ?string
    {
        if ($marketType === null) {
            return null;
        }
        $lower = strtolower($marketType);

        return \in_array($lower, ['perp', 'future', 'futures'], true) ? 'perpetual' : $lower;
    }
}
