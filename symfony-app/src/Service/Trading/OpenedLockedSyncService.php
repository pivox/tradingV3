<?php

declare(strict_types=1);

namespace App\Service\Trading;

use App\Service\Bitmart\Private\PositionsService;
use App\Service\Pipeline\MtfPipelineViewService;
use App\Service\Pipeline\MtfStateService;
use Psr\Log\LoggerInterface;

final class OpenedLockedSyncService
{
    public function __construct(
        private readonly PositionsService $positionsService,
        private readonly MtfPipelineViewService $pipelineView,
        private readonly MtfStateService $mtfState,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun = false,
    ) {}

    /**
     * Synchronise les verrous MTF (LOCKED_*) avec les positions BitMart.
     *
     * @return array<string,mixed>
     */
    public function sync(): array
    {
        $this->logger->info('[OpenedLockedSync] Démarrage synchronisation');
        $openPositions = $this->collectOpenPositions();
        $openSymbols = array_keys($openPositions);
        $pipelines = $this->pipelineView->list();
        $lockedSymbolsBefore = [];
        foreach ($pipelines as $row) {
            if ($this->isLocked($row)) {
                $lockedSymbolsBefore[] = $row['symbol'];
            }
        }

        $lockedLookup = array_flip($lockedSymbolsBefore);
        $openLookup = array_flip($openSymbols);

        $removedSymbols = array_values(array_diff($lockedSymbolsBefore, $openSymbols));
        $addedSymbols = array_values(array_diff($openSymbols, $lockedSymbolsBefore));
        $keptSymbols = array_values(array_intersect($lockedSymbolsBefore, $openSymbols));

        $changedToLocked = [];
        $removedLocks = [];

        if (!$this->dryRun) {
            foreach ($removedSymbols as $symbol) {
                $eventId = $this->buildEventId('POSITION_CLOSED', $symbol);
                $this->mtfState->applyPositionClosed($eventId, $symbol, $this->executionTimeframesForSymbol($symbol));
                $removedLocks[] = $symbol;
            }
            foreach ($addedSymbols as $symbol) {
                $eventId = $this->buildEventId('POSITION_OPENED', $symbol);
                $this->mtfState->applyPositionOpened($eventId, $symbol, $this->executionTimeframesForSymbol($symbol));
                $changedToLocked[] = $symbol;
            }
        }

        return [
            'bitmart_open_symbols' => $openSymbols,
            'locked_symbols_before' => $lockedSymbolsBefore,
            'removed_symbols' => $removedSymbols,
            'kept_symbols' => $keptSymbols,
            'cleared_order_ids' => [],
            'changed_to_opened_locked' => $changedToLocked,
            'created_pipelines_pending' => [],
            'added_default_tp_sl' => [],
            'tp_sl_orders' => [],
            'total_unlocked' => count($removedLocks),
        ];
    }

    /**
     * @return array<string,array<mixed>>
     */
    private function collectOpenPositions(): array
    {
        $bm = $this->positionsService->list();
        $positions = [];
        foreach (($bm['data'] ?? []) as $pos) {
            $qty = (float)($pos['current_amount'] ?? $pos['position_amount'] ?? 0);
            if ($qty !== 0.0) {
                $symbol = strtoupper((string)($pos['symbol'] ?? ''));
                if ($symbol === '') {
                    continue;
                }
                $positions[$symbol] = $pos;
            }
        }
        return $positions;
    }

    private function isLocked(array $pipeline): bool
    {
        foreach ($pipeline['eligibility'] ?? [] as $row) {
            $status = strtoupper((string)($row['status'] ?? ''));
            if (in_array($status, ['LOCKED_POSITION','LOCKED_ORDER'], true)) {
                return true;
            }
        }
        return false;
    }

    private function executionTimeframesForSymbol(string $symbol): array
    {
        $pipeline = $this->pipelineView->get($symbol);
        if (!$pipeline) {
            return ['1m','5m'];
        }
        $tf = $pipeline['current_timeframe'] ?? '1m';
        return [$tf];
    }

    private function buildEventId(string $type, string $symbol): string
    {
        return sprintf('%s|%s|%d', $type, strtoupper($symbol), (int)(microtime(true) * 1000));
    }
}
