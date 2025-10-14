<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contract;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contract>
 */
class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }

    /**
     * Récupère tous les contrats actifs selon les critères de trading
     */
    public function findActiveContracts(): array
    {
        $qb = $this->createQueryBuilder('c');
        
        $qb->where('c.quoteCurrency = :quoteCurrency')
           ->andWhere('c.status = :status')
           ->andWhere('c.volume24h >= :minVolume')
           ->setParameter('quoteCurrency', 'USDT')
           ->setParameter('status', 'Trading')
           ->setParameter('minVolume', '500000')
           ->orderBy('c.symbol', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère un contrat par son symbole
     */
    public function findBySymbol(string $symbol): ?Contract
    {
        return $this->findOneBy(['symbol' => $symbol]);
    }

    /**
     * Récupère les contrats par devise de quote
     */
    public function findByQuoteCurrency(string $quoteCurrency): array
    {
        return $this->findBy(['quoteCurrency' => $quoteCurrency]);
    }

    /**
     * Récupère les contrats par statut
     */
    public function findByStatus(string $status): array
    {
        return $this->findBy(['status' => $status]);
    }

    /**
     * Compte le nombre de contrats actifs
     */
    public function countActiveContracts(): int
    {
        $qb = $this->createQueryBuilder('c');
        
        $qb->select('COUNT(c.id)')
           ->where('c.quoteCurrency = :quoteCurrency')
           ->andWhere('c.status = :status')
           ->andWhere('c.volume24h >= :minVolume')
           ->setParameter('quoteCurrency', 'USDT')
           ->setParameter('status', 'Trading')
           ->setParameter('minVolume', '500000');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Récupère les statistiques des contrats
     */
    public function getContractStats(): array
    {
        $qb = $this->createQueryBuilder('c');
        
        $stats = $qb
            ->select([
                'c.quoteCurrency',
                'c.status',
                'COUNT(c.id) as count',
                'AVG(c.volume24h) as avgVolume',
                'SUM(c.volume24h) as totalVolume'
            ])
            ->groupBy('c.quoteCurrency', 'c.status')
            ->orderBy('c.quoteCurrency', 'ASC')
            ->addOrderBy('c.status', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $stats;
    }

    /**
     * UPSERT un contrat (insert ou update)
     */
    public function upsertContract(array $contractData): Contract
    {
        $symbol = $contractData['symbol'] ?? '';
        if (!$symbol) {
            throw new \InvalidArgumentException('Symbol is required');
        }

        $contract = $this->findBySymbol($symbol);
        
        if (!$contract) {
            $contract = new Contract();
            $contract->setSymbol($symbol);
        }

        // Mettre à jour les données
        $contract->setName($contractData['name'] ?? null);
        $contract->setProductType($contractData['product_type'] ?? null);
        $contract->setOpenTimestamp($contractData['open_timestamp'] ?? null);
        $contract->setExpireTimestamp($contractData['expire_timestamp'] ?? null);
        $contract->setSettleTimestamp($contractData['settle_timestamp'] ?? null);
        $contract->setBaseCurrency($contractData['base_currency'] ?? null);
        $contract->setQuoteCurrency($contractData['quote_currency'] ?? null);
        $contract->setLastPrice($contractData['last_price'] ?? null);
        $contract->setVolume24h($contractData['volume_24h'] ?? null);
        $contract->setTurnover24h($contractData['turnover_24h'] ?? null);
        $contract->setStatus($contractData['status'] ?? null);
        $contract->setMinSize($contractData['min_size'] ?? null);
        $contract->setMaxSize($contractData['max_size'] ?? null);
        $contract->setTickSize($contractData['tick_size'] ?? null);
        $contract->setMultiplier($contractData['multiplier'] ?? null);
        $contract->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->getEntityManager()->persist($contract);
        $this->getEntityManager()->flush();

        return $contract;
    }

    /**
     * UPSERT plusieurs contrats en lot
     */
    public function upsertContracts(array $contractsData): int
    {
        $upsertedCount = 0;
        $batchSize = 50;

        foreach (array_chunk($contractsData, $batchSize) as $batch) {
            foreach ($batch as $contractData) {
                try {
                    $this->upsertContract($contractData);
                    $upsertedCount++;
                } catch (\Exception $e) {
                    // Log l'erreur mais continue avec les autres contrats
                    error_log("Error upserting contract {$contractData['symbol']}: " . $e->getMessage());
                }
            }
            
            // Flush le batch
            $this->getEntityManager()->flush();
            $this->getEntityManager()->clear();
        }

        return $upsertedCount;
    }

    /**
     * Retourne la liste des symboles des contrats actifs, en excluant les blacklists.
     * Critères appliqués selon les colonnes disponibles: status=Trading, quote=USDT,
     * volume_24h >= seuil, et non expiré (expire_timestamp NULL, 0 ou > maintenant).
     * @return string[]
     */
    public function allActiveSymbolNames(array $symbols = []): array
    {
        // timestamps stockés en ms dans la table; on se base sur l'heure UTC actuelle
        $nowMs = (int) round(microtime(true) * 1000);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $openTimestamp = $now->sub(new \DateInterval('PT880H'))->getTimestamp();


        $qb = $this->createQueryBuilder('contract')
            ->select('contract.symbol AS symbol')
            ->andWhere('contract.status = :status')->setParameter('status', 'Trading')
            ->andWhere('contract.quoteCurrency = :quoteCurrency')->setParameter('quoteCurrency', 'USDT')
            ->andWhere('(contract.expireTimestamp IS NULL OR contract.expireTimestamp = 0 OR contract.expireTimestamp > :nowMs)')->setParameter('nowMs', $nowMs)
            ->andWhere('contract.volume24h >= :minVolume')->setParameter('minVolume', '500000')
            ->andWhere('contract.openTimestamp > :openTimestamp')->setParameter('openTimestamp', $openTimestamp)
            ->orderBy('contract.symbol', 'ASC')
        ;
        if ($symbols !== []) {
            $qb->andWhere($qb->expr()->in('contract.symbol', ':symbols'));
            $qb->setParameter('symbols', $symbols);
        }

        // Exclure contrats blacklistés si table présente
        $sub = $this->getEntityManager()->createQueryBuilder()
            ->select('b.symbol')
            ->from('App\\Entity\\BlacklistedContract', 'b')
            ->where('b.symbol IS NOT NULL')
            ->andWhere('(b.expiresAt IS NULL OR b.expiresAt > :dtnow)')
        ;
        $qb->andWhere($qb->expr()->notIn('contract.symbol', $sub->getDQL()));

        // Exclure les symboles temporairement désactivés via MtfSwitch
        $mtfSub = $this->getEntityManager()->createQueryBuilder()
            ->select('SUBSTRING(m.switchKey, 8)') // Remove 'SYMBOL:' prefix
            ->from('App\\Entity\\MtfSwitch', 'm')
            ->where('m.switchKey LIKE :symbolPattern')
            ->andWhere('m.isOn = :isOff')
            ->andWhere('(m.expiresAt IS NULL OR m.expiresAt > :dtnow)')
        ;
        $qb->andWhere($qb->expr()->notIn('contract.symbol', $mtfSub->getDQL()));
        
        // Définir tous les paramètres nécessaires pour les sous-requêtes
        $qb->setParameter('dtnow', $now);
        $qb->setParameter('symbolPattern', 'SYMBOL:%');
        $qb->setParameter('isOff', false);

        $rows = $qb->getQuery()->getScalarResult();
        return array_map(static fn(array $row) => $row['symbol'], $rows);
    }

    public function findWithFilters(?string $status = null, ?string $symbol = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.symbol', 'ASC');

        if ($status) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        if ($symbol) {
            $qb->andWhere('c.symbol LIKE :symbol')
                ->setParameter('symbol', '%' . $symbol . '%');
        }

        return $qb->getQuery()->getResult();
    }
}
