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
}
