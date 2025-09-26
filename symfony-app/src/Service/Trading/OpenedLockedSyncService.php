<?php

namespace App\Service\Trading;

use App\Entity\ContractPipeline;
use App\Repository\ContractPipelineRepository;
use App\Service\Account\Bitmart\BitmartFuturesClient;
use Doctrine\ORM\EntityManagerInterface;

final class OpenedLockedSyncService
{
    public function __construct(
        private readonly BitmartFuturesClient $bm,
        private readonly ContractPipelineRepository $repo,
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * 1) Récupère les positions ouvertes sur BitMart (symboles avec qty != 0).
     * 2) Liste les pipelines en OPENED_LOCKED.
     * 3) Déverrouille (status -> pending) ceux qui ne sont plus ouverts.
     *
     * @return array{
     *   bitmart_open_symbols:string[],
     *   locked_symbols_before:string[],
     *   removed_symbols:string[],
     *   kept_symbols:string[],
     *   total_unlocked:int
     * }
     */
    public function sync(): array
    {
        // (1) Symboles effectivement ouverts chez BitMart
        $bm = $this->bm->getPositions();
        $openSymbols = [];
        foreach (($bm['data'] ?? []) as $pos) {
            $qty = (float)($pos['current_amount'] ?? 0);
            if ($qty !== 0.0) {
                $openSymbols[] = strtoupper((string)$pos['symbol']);
            }
        }
        $openSymbols = array_values(array_unique($openSymbols));

        // (2) Pipelines OPENED_LOCKED
        $lockedPipelines = $this->repo->findAllOpenedLocked();
        $lockedSymbolsBefore = [];
        foreach ($lockedPipelines as $p) {
            $lockedSymbolsBefore[] = strtoupper($p->getContract()->getSymbol());
        }

        // (3) Déverrouiller ceux qui ne sont plus ouverts côté BitMart
        $removed = [];
        $kept = [];

        foreach ($lockedPipelines as $pipeline) {
            $sym = strtoupper($pipeline->getContract()->getSymbol());
            if (!in_array($sym, $openSymbols, true)) {
                // ⇒ la position est fermée chez BitMart, on enlève du tableau en remettant le statut à 'pending'
                $pipeline->setStatus(ContractPipeline::STATUS_PENDING)->touchUpdatedAt();
                $this->em->persist($pipeline);
                $removed[] = $sym;
            } else {
                $kept[] = $sym;
            }
        }

        if (!empty($removed)) {
            $this->em->flush();
        }

        return [
            'bitmart_open_symbols' => $openSymbols,
            'locked_symbols_before' => array_values(array_unique($lockedSymbolsBefore)),
            'removed_symbols' => array_values(array_unique($removed)), // ceux qu’on a “sortis du tableau”
            'kept_symbols' => array_values(array_unique($kept)),       // ceux qui restent OPENED_LOCKED
            'total_unlocked' => count($removed),
        ];
    }
}
