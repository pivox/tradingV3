<?php

namespace App\Service;

use App\Entity\Contract;
use App\Entity\Kline;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\KlinePersister;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class KlineService
{
    public function __construct(
        private EntityManagerInterface $em,
        private BitmartFetcher $fetcher,
        private KlinePersister $persister,
        private LoggerInterface $logger
    ) {}

    public function fetchMissingAndReturnAll(string $symbol, \DateTimeImmutable $start, \DateTimeImmutable$end, int $step): array
    {
        // 1. récupérer le contrat
        $contract = $this->em->getRepository(Contract::class)->find($symbol);
        if (!$contract) {
            throw new \InvalidArgumentException("Unknown contract symbol: $symbol");
        }

        // 2. Générer les timestamps attendus
        $stepInterval = new \DateInterval('PT' . $step . 'M');
        $expectedTimestamps = [];
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->add($stepInterval)) {
            $expectedTimestamps[$cursor->getTimestamp()] = true;
        }

        // 3. Récupérer les timestamps existants
        $qb = $this->em->createQueryBuilder();
        $qb->select('k.timestamp')
            ->from(Kline::class, 'k')
            ->where('k.contract = :contract')
            ->andWhere('k.timestamp BETWEEN :start AND :end')
            ->andWhere('k.step = :step')
            ->setParameter('contract', $contract)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('step', $step);

        $existingTimestamps = array_map(
            fn(\DateTimeInterface $dt) => $dt->getTimestamp(),
            array_column($qb->getQuery()->getResult(), 'timestamp')
        );

        foreach ($existingTimestamps as $ts) {
            unset($expectedTimestamps[$ts]);
        }
        // 4. Télécharger les Klines manquants
        if (!empty($expectedTimestamps)) {
            $missingStart = (new \DateTimeImmutable())->setTimestamp(min(array_keys($expectedTimestamps)));
            $missingEnd = (new \DateTimeImmutable())->setTimestamp(max(array_keys($expectedTimestamps)));

            $fetchedDtos = $this->fetcher->fetchKlines($symbol, $missingStart, $missingEnd, $step);
            $this->logger->info('qsdqsd');
            $this->logger->info(json_encode($fetchedDtos, JSON_PRETTY_PRINT));
            $persisted = $this->persister->persist($fetchedDtos, $contract, (string) $step);
        }

        // 5. Retourner tous les Klines de la plage, persistés inclus
        return $this->em->getRepository(Kline::class)->createQueryBuilder('k')
            ->where('k.contract = :contract')
            ->andWhere('k.timestamp BETWEEN :start AND :end')
            ->andWhere('k.step = :step')
            ->orderBy('k.timestamp', 'ASC')
            ->setParameter('contract', $contract)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('step', $step)
            ->getQuery()
            ->getResult();
    }
}
