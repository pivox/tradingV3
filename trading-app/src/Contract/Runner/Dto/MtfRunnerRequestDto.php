<?php

declare(strict_types=1);

namespace App\Contract\Runner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Provider\Context\ExchangeContextResolver;

/**
 * DTO pour les requêtes d'exécution du Runner MTF
 */
final class MtfRunnerRequestDto
{
    /**
     * @param string[] $symbols
     * @param array{open_positions?: array<int,mixed>, open_orders?: array<int,mixed>}|null $openStateSnapshot
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
        /**
         * Instantané de l'état ouvert (positions/ordres) fourni par l'orchestrateur
         * pour éviter un appel exchange par set (SF-002b).
         *
         * @var array{open_positions?: array<int,mixed>, open_orders?: array<int,mixed>}|null
         */
        public readonly ?array $openStateSnapshot = null,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        [$exchange, $marketType] = self::extractContext($data);

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
            openStateSnapshot: self::extractOpenStateSnapshot($data),
        );
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
            'open_state_snapshot' => $this->openStateSnapshot,
        ];
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
        return ExchangeContextResolver::normalizeExchange($value);
    }

    private static function normalizeMarketType(string $value): MarketType
    {
        return ExchangeContextResolver::normalizeMarketType($value);
    }
}
