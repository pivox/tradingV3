<?php

declare(strict_types=1);

namespace App\MtfRunner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContextResolver;
use App\Trading\Lineage\LineageContext;

/**
 * DTO pour les requêtes d'exécution du Runner MTF
 */
final class MtfRunnerRequestDto
{
    public readonly LineageContext $lineageContext;

    /**
     * @param string[] $symbols
     * @param array{open_positions: array<int,mixed>, open_orders: array<int,mixed>}|null $openStateSnapshot
     */
    public function __construct(
        public readonly array $symbols = [],
        public readonly bool $dryRun = false,
        public readonly bool $forceRun = false,
        public readonly ?string $currentTf = null,
        public readonly bool $forceTimeframeCheck = false,
        public readonly bool $skipContextValidation = false,
        public readonly bool $lockPerSymbol = false,
        public readonly bool $skipOpenStateFilter = false,
        public readonly ?string $userId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?Exchange $exchange = null,
        public readonly ?MarketType $marketType = null,
        public readonly int $workers = 1,
        public readonly bool $syncTables = true,
        public readonly bool $processTpSl = true,
        public readonly ?string $profile = null,
        public readonly ?string $validationMode = null,
        /**
         * Instantané de l'état ouvert (positions/ordres) fourni par l'orchestrateur
         * pour éviter un appel exchange par set (SF-002b). Forme attendue :
         * ['open_positions' => array<int,mixed>, 'open_orders' => array<int,mixed>].
         *
         * @var array{open_positions?: array<int,mixed>, open_orders?: array<int,mixed>}|null
         */
        public readonly ?array $openStateSnapshot = null,
        /**
         * OBS-003 — lineage d'orchestration (en-têtes `X-Run-Id` / `X-Run-Correlation-Id`
         * / `X-Orchestration-Dashboard-Id` / `X-Orchestration-Set-Id`). Tous nullables :
         * CLI et appel HTTP legacy (sans en-têtes) restent strictement inchangés.
         */
        public readonly ?string $originalRunId = null,
        public readonly ?string $correlationRunId = null,
        public readonly ?string $dashboardId = null,
        public readonly ?string $setId = null,
        ?LineageContext $lineageContext = null,
    ) {
        $this->lineageContext = $lineageContext ?? LineageContext::legacy(
            symbol: $symbols[0] ?? null,
            exchange: $exchange?->value,
            marketType: $marketType?->value,
            mtfProfile: $profile,
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        [$exchange, $marketType] = self::extractContext($data);
        [$profile, $validationMode] = self::extractProfileAndMode($data);

        $lineageContext = self::buildLineageContext($data, $exchange, $marketType, $profile);

        return new self(
            symbols: $data['symbols'] ?? [],
            dryRun: (bool) ($data['dry_run'] ?? false),
            forceRun: (bool) ($data['force_run'] ?? false),
            currentTf: $data['current_tf'] ?? null,
            forceTimeframeCheck: (bool) ($data['force_timeframe_check'] ?? false),
            skipContextValidation: (bool) ($data['skip_context'] ?? false),
            lockPerSymbol: (bool) ($data['lock_per_symbol'] ?? false),
            skipOpenStateFilter: (bool) ($data['skip_open_state_filter'] ?? false),
            userId: $data['user_id'] ?? null,
            ipAddress: $data['ip_address'] ?? null,
            exchange: $exchange,
            marketType: $marketType,
            workers: max(1, (int) ($data['workers'] ?? 1)),
            syncTables: (bool) ($data['sync_tables'] ?? true),
            processTpSl: (bool) ($data['process_tp_sl'] ?? true),
            profile: $profile,
            validationMode: $validationMode,
            openStateSnapshot: self::extractOpenStateSnapshot($data),
            originalRunId: self::nonEmptyString($data['run_id'] ?? $data['original_run_id'] ?? null),
            correlationRunId: self::nonEmptyString($data['correlation_run_id'] ?? null),
            dashboardId: self::nonEmptyString($data['dashboard_id'] ?? $data['orchestration_dashboard_id'] ?? null),
            setId: self::nonEmptyString($data['set_id'] ?? $data['orchestration_set_id'] ?? null),
            lineageContext: $lineageContext,
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'symbols' => $this->symbols,
            'dry_run' => $this->dryRun,
            'force_run' => $this->forceRun,
            'current_tf' => $this->currentTf,
            'force_timeframe_check' => $this->forceTimeframeCheck,
            'skip_context' => $this->skipContextValidation,
            'lock_per_symbol' => $this->lockPerSymbol,
            'skip_open_state_filter' => $this->skipOpenStateFilter,
            'user_id' => $this->userId,
            'ip_address' => $this->ipAddress,
            'exchange' => $this->exchange?->value,
            'market_type' => $this->marketType?->value,
            'workers' => $this->workers,
            'sync_tables' => $this->syncTables,
            'process_tp_sl' => $this->processTpSl,
            'profile' => $this->profile,
            'validation_mode' => $this->validationMode,
            'open_state_snapshot' => $this->openStateSnapshot,
            'run_id' => $this->originalRunId,
            'correlation_run_id' => $this->correlationRunId,
            'dashboard_id' => $this->dashboardId,
            'set_id' => $this->setId,
            'lineage_context' => $this->lineageContext->toArray(),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function buildLineageContext(array $data, ?Exchange $exchange, ?MarketType $marketType, ?string $profile): LineageContext
    {
        if (isset($data['lineage_context']) && \is_array($data['lineage_context'])) {
            return LineageContext::fromArray($data['lineage_context']);
        }

        $hasOrchestratorLineage = self::nonEmptyString($data['run_id'] ?? $data['original_run_id'] ?? $data['orchestration_run_id'] ?? null) !== null
            || self::nonEmptyString($data['set_id'] ?? $data['orchestration_set_id'] ?? null) !== null
            || self::nonEmptyString($data['dashboard_id'] ?? $data['orchestration_dashboard_id'] ?? null) !== null;

        $payload = $data + [
            'profile' => $profile,
            'exchange' => $exchange?->value,
            'market_type' => $marketType?->value,
        ];

        if ($hasOrchestratorLineage || self::nonEmptyString($data['origin'] ?? null) !== null) {
            return LineageContext::fromOrchestratorPayload($payload);
        }

        $symbols = isset($data['symbols']) && is_array($data['symbols']) ? $data['symbols'] : [];

        return LineageContext::legacy(
            symbol: is_string($symbols[0] ?? null) ? $symbols[0] : null,
            exchange: $exchange?->value,
            marketType: $marketType?->value,
            mtfProfile: $profile,
        );
    }

    /**
     * Normalise l'instantané d'état ouvert fourni dans le payload.
     *
     * @param array<string,mixed> $data
     * @return array{open_positions: array<int,mixed>, open_orders: array<int,mixed>}|null
     */
    private static function extractOpenStateSnapshot(array $data): ?array
    {
        $snapshot = $data['open_state_snapshot'] ?? null;
        if (!is_array($snapshot)) {
            return null;
        }

        $positions = $snapshot['open_positions'] ?? null;
        $orders = $snapshot['open_orders'] ?? null;

        // Un snapshot n'est une source fiable que s'il contient explicitement les deux
        // clés sous forme de tableaux. Un payload mal formé ({} ou clés manquantes/
        // non-tableaux) retourne null pour que le garde fail-closed en live se déclenche
        // au lieu d'exécuter le run sans état d'ouverture réel. Un snapshot vide mais
        // bien formé (open_positions/open_orders = []) reste valide.
        if (!is_array($positions) || !is_array($orders)) {
            return null;
        }

        return [
            'open_positions' => array_values($positions),
            'open_orders' => array_values($orders),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0: ?Exchange, 1: ?MarketType}
     */
    private static function extractContext(array $data): array
    {
        $exchangeInput = $data['exchange']
            ?? $data['cex']
            ?? null;

        $marketInput = $data['market_type']
            ?? $data['type_contract']
            ?? null;

        $exchange = null;
        if (is_string($exchangeInput) && $exchangeInput !== '') {
            $exchange = self::normalizeExchange($exchangeInput);
        }

        $marketType = null;
        if (is_string($marketInput) && $marketInput !== '') {
            $marketType = self::normalizeMarketType($marketInput);
        }

        return [$exchange, $marketType];
    }

    private static function normalizeExchange(string $value): Exchange
    {
        // Source de vérité partagée avec les endpoints HTTP (cf. SF-001).
        return ExchangeContextResolver::normalizeExchange($value);
    }

    private static function normalizeMarketType(string $value): MarketType
    {
        return ExchangeContextResolver::normalizeMarketType($value);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0: ?string, 1: ?string}
     */
    private static function extractProfileAndMode(array $data): array
    {
        $profileSources = [
            $data['profile'] ?? null,
            $data['mtf_profile'] ?? null,
        ];

        $profile = null;
        foreach ($profileSources as $source) {
            if (is_string($source) && $source !== '') {
                $profile = trim($source);
                break;
            }
        }

        $mode = null;
        $modeCandidates = [
            $data['validation_mode'] ?? null,
            $data['context_mode'] ?? null,
        ];
        foreach ($modeCandidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $mode = strtolower(trim($candidate));
                break;
            }
        }

        $genericMode = $data['mode'] ?? null;
        if (is_string($genericMode) && $genericMode !== '') {
            $genericModeTrimmed = trim($genericMode);
            $lower = strtolower($genericModeTrimmed);
            if (in_array($lower, ['pragmatic', 'strict'], true)) {
                $mode = $lower;
            } elseif ($profile === null) {
                $profile = $genericModeTrimmed;
            }
        }

        return [$profile, $mode];
    }
}
