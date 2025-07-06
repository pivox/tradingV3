<?php

namespace App\Service;

use App\Entity\Contract;
use Doctrine\ORM\EntityManagerInterface;

class SyncStatusService
{
    public function __construct(private EntityManagerInterface $em) {}

    public function isKlineSynced(Contract $contract, int $step, int $minCount = 95): bool
    {
        $cutoff = (new \DateTimeImmutable())->modify('-' . ($step * 100) . ' minutes')->format('Y-m-d H:i:s');

        $conn = $this->em->getConnection();

        $sql = '
        SELECT *
        FROM kline
        WHERE contract_id = :contract
          AND step = :step
          AND timestamp > :cutoff
        LIMIT :limit
    ';

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('contract', $contract->getSymbol());
        $stmt->bindValue('step', $step);
        $stmt->bindValue('cutoff', $cutoff);
        $stmt->bindValue('limit', $minCount, \PDO::PARAM_INT);
        $x = $stmt->executeStatement();


        return $stmt->executeStatement() >= $minCount;

    }

}
