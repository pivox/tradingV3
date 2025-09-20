<?php

namespace App\Service\Persister;

use App\Entity\Contract;
use App\Entity\Kline;
use Doctrine\ORM\EntityManagerInterface;

class KlinePersister
{
    public function __construct(private EntityManagerInterface $em) {}

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
     * @param Contract|string $contractOrSymbol  (autorise le symbol direct)
     * @param KlineDto[]      $dtos
     */
    public function persistMany(Contract|string $contractOrSymbol, array $dtos, int $step, bool $flush = false)
    {
        // ⚠️ IMPORTANT: reprendre une entité *gérée* (évite l'entité "new/detached")
        $symbol   = $contractOrSymbol instanceof Contract ? $contractOrSymbol->getSymbol() : $contractOrSymbol;
        $contract = $this->em->getReference(Contract::class, $symbol);
        $list =[];
        foreach ($dtos as $dto) {
            $k = (new Kline())->fillFromDto($dto, $contract, $step);
            $list[] = $k;
            $this->em->persist($k);
        }

        if ($flush) {
            $this->em->flush();
            $this->em->clear(); // OK: on a utilisé getReference() juste avant
        }
        return $list;
    }

    public function clear(): void { $this->em->clear(); }
}
