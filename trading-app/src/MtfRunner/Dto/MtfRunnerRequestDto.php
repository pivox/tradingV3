<?php

declare(strict_types=1);

namespace App\MtfRunner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

/**
 * DTO pour les requêtes d'exécution du Runner MTF
 */
final class MtfRunnerRequestDto
{
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
    ) {}

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
        );
    }

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
        ];
    }

    /**
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
        return match (strtolower(trim($value))) {
            'bitmart' => Exchange::BITMART,
            default => throw new \InvalidArgumentException(sprintf('Unsupported exchange "%s"', $value)),
        };
    }

    private static function normalizeMarketType(string $value): MarketType
    {
        return match (strtolower(trim($value))) {
            'perpetual', 'perp', 'future', 'futures' => MarketType::PERPETUAL,
            'spot' => MarketType::SPOT,
            default => throw new \InvalidArgumentException(sprintf('Unsupported market type "%s"', $value)),
        };
    }
}


