<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BatchRun;
use App\Entity\BatchRunItem;
use App\Event\TradingAnalysisRequested;
use App\Repository\BatchRunItemRepository;
use App\Repository\BatchRunRepository;
use App\Service\Bitmart\BlacklistService;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use App\Util\TimeframeHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;

final class BatchRunOrchestrator
{
    public function __construct(
        private readonly BatchRunRepository $runs,
        private readonly BatchRunItemRepository $items,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $locks,
        private readonly BlacklistService $blacklist,
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly \App\Repository\ContractRepository $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Trigger depuis Temporal schedule: ask-for-refresh-{tf}
     * - Crée (ou récupère) le BatchRun du slot courant
     * - Si status=running => no-op
     * - Si status=created => fait le snapshot (si nécessaire) puis poke l’api_rate_limiter (drain)
     */
    public function askForRefresh(string $tf): void
    {
        $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($tf);
        $slotEnd   = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes, new \DateTimeZone('UTC')); // fin du slot
        $slotStart = $slotEnd->modify('-'.$tfMinutes.' minutes');

        $run = $this->runs->getOrCreate($tf, $slotStart, $slotEnd);

        if ($run->getStatus() === BatchRun::STATUS_RUNNING) {
            return; // déjà en cours
        }

        if ($run->getStatus() !== BatchRun::STATUS_CREATED) {
            return; // slot passé ou clôturé
        }

        // lock snapshot pour éviter double snapshot
        $lock = $this->locks->createLock(sprintf('snapshot-%s-%s', $tf, $slotStart->format('YmdHis')));
        if (!$lock->acquire()) {
            // Un autre process snapshot -> tenter un poke si déjà prêt
            if ($run->isSnapshotDone()) {
                $this->pokeApiRateLimiter($run);
            }
            return;
        }

        try {
            if (!$run->isSnapshotDone()) {
                $symbols = $this->buildSnapshotSymbols($run); // hérite du TF précédent (ou BitMart si 4h)
                $this->persistSnapshot($run, $symbols['list'], $symbols['source']);
            }
            $this->pokeApiRateLimiter($run);
        } finally {
            $lock->release();
        }
    }

    /**
     * Construit la liste des symboles pour le snapshot.
     *  - 4h : depuis BitMart (à implémenter via ton client public) + filtres + blacklist
     *  - autres TF : héritage du dernier BatchRun SUCCESS du TF précédent (<= slotEnd)
     */
    private function buildSnapshotSymbols(BatchRun $run): array
    {
        $tf = $run->getTimeframe();
        $slotEnd = $run->getSlotEndUtc();

        if ($tf === '4h') {
            // TODO: remplace par ton client BitMart public (filtres status/historique)
            $symbols = $this->getBitmartActiveContracts(); // ex: ['BTCUSDT','ETHUSDT',...]
            $symbols = array_values(array_filter($symbols, fn($s) => !$this->blacklist->isBlacklisted($s)));
            return ['list' => $symbols, 'source' => 'bitmart'];
        }

        $prevTf = $this->prevTimeframe($tf);
        if (!$prevTf) {
            return ['list' => [], 'source' => 'cache'];
        }

        $prevRun = $this->runs->findLastSuccessUntil($prevTf, $slotEnd);
        if (!$prevRun) {
            // fallback cache (top volume/OI que tu maintiens ailleurs)
            $symbols = $this->getWatchlistCache();
            $symbols = array_values(array_filter($symbols, fn($s) => !$this->blacklist->isBlacklisted($s)));
            return ['list' => $symbols, 'source' => 'cache'];
        }

        // Hériter des items DONE du run précédent
        $prevItems = $this->items->findByRun($prevRun);
        $symbols = [];
        foreach ($prevItems as $it) {
            if ($it->getStatus() === BatchRunItem::STATUS_DONE) {
                $sym = $it->getSymbol();
                if (!$this->blacklist->isBlacklisted($sym)) {
                    $symbols[] = $sym;
                }
            }
        }
        return ['list' => array_values(array_unique($symbols)), 'source' => 'prev_tf'];
    }

    private function prevTimeframe(string $tf): ?string
    {
        $order = ['4h','1h','15m','5m','1m'];
        $i = array_search($tf, $order, true);
        return ($i !== false && $i > 0) ? $order[$i-1] : null;
    }

    private function persistSnapshot(BatchRun $run, array $symbols, string $source): void
    {
        foreach ($symbols as $sym) {
            $this->em->persist(new BatchRunItem($run, $sym));
        }
        $run->markSnapshotDone($source, count($symbols));
        $this->em->flush();
    }

    private function pokeApiRateLimiter(BatchRun $run): void
    {
        if ($run->getStatus() !== BatchRun::STATUS_RUNNING) {
            $run->markRunning();
            $this->em->flush();
        }

        // Enqueue tous les items (idempotent: on ne repousse pas ceux déjà enqueued)
        $workflowRef = new WorkflowRef('api-rate-limiter-workflow', 'ApiRateLimiterClient', 'api_rate_limiter_queue');

        $this->bitmartOrchestrator->reset();
        $this->bitmartOrchestrator->setWorkflowRef($workflowRef);

        $items = $this->items->findByRun($run);
        foreach ($items as $item) {
            if ($item->getStatus() !== BatchRunItem::STATUS_PENDING) {
                continue;
            }
            $item->markEnqueued();
            $run->incEnqueued();

            // NOTE: ici on pousse une requête "getKlines" avec corrélation batch
            // -> à adapter à ta signature existante
            $this->bitmartOrchestrator->requestGetKlines(
                $workflowRef,
                baseUrl: 'http://nginx',
                callback: 'api/v2/callback/bitmart/get-kline',
                contract: $item->getSymbol(),
                timeframe: $run->getTimeframe(),
                limit:  260,
                start:  $run->getSlotStartUtc(),
                end:    $run->getSlotEndUtc(),
                note:   'batch-refresh',
                batchId: $run->getId()
            );
        }

        $this->bitmartOrchestrator->go();
        $this->em->flush();
    }

    /**
     * Callback terminal (done/failed/skipped) idempotent:
     * - ne transitionne l'item en terminal que si non encore terminal
     * - décrémente remaining **une seule fois**
     * - clôture le batch si remaining==0
     * - si BatchRun du TF+1 existe en CREATED, on le poke (et on fait son snapshot si nécessaire)
     */
    public function handleKlineCallback(int $batchRunId, string $symbol, string $terminalStatus, ?string $error = null): void
    {
        $run = $this->runs->find($batchRunId);
        if (!$run) return;

        $item = $this->items->findOneByRunAndSymbol($run, $symbol);
        if (!$item) return;

        if (in_array($item->getStatus(), [BatchRunItem::STATUS_DONE, BatchRunItem::STATUS_FAILED, BatchRunItem::STATUS_SKIPPED], true)) {
            return; // idempotence: déjà terminal
        }

        // Passe en terminal + décrément
        switch ($terminalStatus) {
            case BatchRunItem::STATUS_DONE:
                $item->markDone();
                $run->incCompleted();
                break;
            case BatchRunItem::STATUS_FAILED:
                $item->markFailed($error);
                $run->incFailed();
                break;
            default:
                $item->markSkipped($error);
                $run->incSkipped();
        }

        $run->decRemaining();
        $this->em->flush();

        // Clôture + chainage éventuel
        if ($run->getRemaining() === 0) {
            $run->markSuccess();
            $this->em->flush();

            // Dispatch trading analysis event for all DONE items
            $this->dispatchTradingAnalysis($run);

            // Si un BatchRun du TF suivant est en CREATED pour le slot correspondant, poke-le
            $nextTf = $this->nextTimeframe($run->getTimeframe());
            if ($nextTf) {
                $nextRun = $this->runs->findCreatedOrRunning($nextTf, $run->getSlotEndUtc()); // préfère CREATED, sinon RUNNING = no-op
                if ($nextRun && $nextRun->getStatus() === BatchRun::STATUS_CREATED) {
                    // Assurer snapshot (si pas fait) + poke
                    $lock = $this->locks->createLock(sprintf('snapshot-%s-%s', $nextTf, $nextRun->getSlotStartUtc()->format('YmdHis')));
                    if ($lock->acquire()) {
                        try {
                            if (!$nextRun->isSnapshotDone()) {
                                $symbols = $this->buildSnapshotSymbols($nextRun);
                                $this->persistSnapshot($nextRun, $symbols['list'], $symbols['source']);
                            }
                            $this->pokeApiRateLimiter($nextRun);
                        } finally {
                            $lock->release();
                        }
                    } else {
                        // si un autre process fait le snapshot, on retentera au prochain événement/callback
                    }
                }
            }
        }
    }

    private function nextTimeframe(string $tf): ?string
    {
        $order = ['4h','1h','15m','5m','1m'];
        $i = array_search($tf, $order, true);
        return ($i !== false && $i < count($order)-1) ? $order[$i+1] : null;
    }

    private function getBitmartActiveContracts(): array
    {
        return $this->contractRepository->allActiveSymbolNames();
    }

    private function getWatchlistCache(): array
    {
        return $this->contractRepository->findTopByVolumeOrOI(100);
    }

    /**
     * Dispatch TradingAnalysisRequested event for all DONE items in the batch
     */
    private function dispatchTradingAnalysis(BatchRun $run): void
    {
        $items = $this->items->findByRun($run);
        foreach ($items as $item) {
            if ($item->getStatus() === BatchRunItem::STATUS_DONE) {
                $event = new TradingAnalysisRequested(
                    symbol: $item->getSymbol(),
                    timeframe: $run->getTimeframe(),
                    limit: 270
                );
                $this->eventDispatcher->dispatch($event);
            }
        }
    }
}
