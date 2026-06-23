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
    public const ORIGIN_ORCHESTRATOR = 'orchestrator';
    public const ORIGIN_LEGACY = 'legacy';
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_REPLAY = 'replay';

    public function __construct(
        public string $origin,
        public ?string $orchestrationRunId = null,
        public ?string $correlationRunId = null,
        public ?string $orchestrationSetId = null,
        public ?string $orchestrationDashboardId = null,
        public ?string $mtfProfile = null,
        public ?string $exchange = null,
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
    ) {
        if (!\in_array($origin, [self::ORIGIN_ORCHESTRATOR, self::ORIGIN_LEGACY, self::ORIGIN_MANUAL, self::ORIGIN_REPLAY], true)) {
            throw new LineageContextException(sprintf('origin "%s" non supporte.', $origin));
        }
        if ($attemptNumber < 1) {
            throw new LineageContextException('attempt_number doit etre >= 1.');
        }
        if ($origin === self::ORIGIN_REPLAY && $replayOfRunId === null && $replayOfCorrelationId === null) {
            throw new LineageContextException('Un replay doit référencer le run ou la correlation d origine.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromOrchestratorPayload(array $payload): self
    {
        $runId = self::firstString($payload, ['run_id', 'original_run_id', 'orchestration_run_id']);
        $correlationRunId = self::string($payload['correlation_run_id'] ?? null) ?? $runId;
        $setId = self::sameAlias($payload, 'set_id', 'orchestration_set_id');
        $dashboardId = self::sameAlias($payload, 'dashboard_id', 'orchestration_dashboard_id');
        $profile = self::sameAlias($payload, 'profile', 'mtf_profile');
        $exchange = self::normalizeExchange(self::firstString($payload, ['exchange', 'cex']));
        $marketType = self::normalizeMarketType(self::firstString($payload, ['market_type', 'type_contract']));
        $origin = self::string($payload['origin'] ?? null) ?? self::ORIGIN_ORCHESTRATOR;

        return new self(
            origin: strtolower($origin),
            orchestrationRunId: $runId,
            correlationRunId: $correlationRunId,
            orchestrationSetId: $setId,
            orchestrationDashboardId: $dashboardId,
            mtfProfile: $profile,
            exchange: $exchange,
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
        return new self(
            origin: self::string($data['origin'] ?? null) ?? self::ORIGIN_LEGACY,
            orchestrationRunId: self::string($data['orchestration_run_id'] ?? null),
            correlationRunId: self::string($data['correlation_run_id'] ?? null),
            orchestrationSetId: self::string($data['orchestration_set_id'] ?? null),
            orchestrationDashboardId: self::string($data['orchestration_dashboard_id'] ?? null),
            mtfProfile: self::string($data['mtf_profile'] ?? $data['profile'] ?? null),
            exchange: self::normalizeExchange(self::string($data['exchange'] ?? null)),
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
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'origin' => $this->origin,
            'orchestration_run_id' => $this->orchestrationRunId,
            'correlation_run_id' => $this->correlationRunId,
            'orchestration_set_id' => $this->orchestrationSetId,
            'orchestration_dashboard_id' => $this->orchestrationDashboardId,
            'mtf_profile' => $this->mtfProfile,
            'profile' => $this->mtfProfile,
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
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string,mixed>
     */
    public function redacted(): array
    {
        return $this->toArray();
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
