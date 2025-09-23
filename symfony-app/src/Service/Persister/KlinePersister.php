<?php

namespace App\Service\Persister;

use App\Entity\Contract;
use App\Entity\Kline;
use App\Repository\KlineRepository;
use Doctrine\ORM\EntityManagerInterface;

class KlinePersister
{
    public function __construct(
        private EntityManagerInterface $em,
        private KlineRepository $klineRepository,
    ) {}

    /**
     * Persiste une liste "brute" (sans dédoublonnage).
     * Conserve pour compat / usage direct si besoin.
     */
    public function persist(array $klines, Contract $contract, int $step): array
    {

        $persistedKlines = [];
        foreach ($klines as $dto) {
            $kline = (new Kline())->fillFromDto($dto, $contract, $step);
            $this->em->persist($kline);
            $persistedKlines[] = $kline;
        }
        $this->em->flush();
        return $persistedKlines;
    }

    /**
     * Persiste uniquement les nouvelles bougies (pas de doublon sur (contract, step, timestamp)).
     *
     * @param Contract|string $contractOrSymbol  (autorise le symbol direct)
     * @param array           $dtos              Liste de DTOs Kline (Bitmart)
     * @param int             $step              Minutes du timeframe (1,5,15,60,240,…)
     * @param bool            $flush             flush immédiat
     *
     * @return Kline[]        Les entités effectivement persistées (nouvelles)
     */
    public function persistMany(Contract|string $contractOrSymbol, array $dtos, int $step, bool $flush = false): array
    {
        // ⚠️ IMPORTANT: reprendre une entité *gérée* (évite l'entité "new/detached")
        $symbol   = $contractOrSymbol instanceof Contract ? $contractOrSymbol->getSymbol() : $contractOrSymbol;
        /** @var Contract $contractRef */
        $contractRef = $this->em->getReference(Contract::class, $symbol);

        if (empty($dtos)) {
            if ($flush) { $this->em->flush(); $this->em->clear(); }
            return [];
        }

        // 1) Prépare les candidats en mémoire (sans persist) + collecte des timestamps
        $candidates = [];   // [ [ 'entity' => Kline, 'ts' => int ], ... ]
        $tsList     = [];   // [int, int, ...]
        $minTs = PHP_INT_MAX;
        $maxTs = PHP_INT_MIN;

        foreach ($dtos as $dto) {
            $k = (new Kline())->fillFromDto($dto, $contractRef, $step);
            $ts = (int) $k->getTimestamp()->format('U'); // open ts
            $candidates[] = ['entity' => $k, 'ts' => $ts];
            $tsList[] = $ts;
            if ($ts < $minTs) { $minTs = $ts; }
            if ($ts > $maxTs) { $maxTs = $ts; }
        }

        if ($minTs === PHP_INT_MAX) {
            if ($flush) { $this->em->flush(); $this->em->clear(); }
            return [];
        }

        // 2) Charge les timestamps déjà présents en base sur la plage [minTs, maxTs]
        //    -> évite un "IN (...) géant" : on récupère par range, puis on met en Set en mémoire
        $existing = $this->klineRepository->fetchOpenTimestampsRange($symbol, $step, $minTs, $maxTs);
        // $existing = array d'int (timestamps seconds)
        $existingSet = array_fill_keys($existing, true);

        // 3) Filtre les doublons (garde uniquement ts non présents)
        $newEntities = [];
        foreach ($candidates as $row) {
            if (!isset($existingSet[$row['ts']])) {
                $this->em->persist($row['entity']);
                $newEntities[] = $row['entity'];
            }
        }

        if ($flush) {
            $this->em->flush();
            $this->em->clear(); // OK: on a utilisé getReference() juste avant
        }

        return $newEntities;
    }

    public function clear(): void
    {
        $this->em->clear();
    }
}
