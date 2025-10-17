<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ValidationCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ValidationCache>
 */
class ValidationCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ValidationCache::class);
    }

    /**
     * Récupère un cache de validation par clé
     */
    public function findByCacheKey(string $cacheKey): ?ValidationCache
    {
        return $this->findOneBy(['cacheKey' => $cacheKey]);
    }

    /**
     * Récupère les caches expirés
     */
    public function findExpiredCaches(): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les caches expirés
     */
    public function deleteExpiredCaches(): int
    {
        return $this->createQueryBuilder('v')
            ->delete()
            ->where('v.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getQuery()
            ->execute();
    }

    /**
     * Récupère tous les caches pour un symbole
     */
    public function findBySymbol(string $symbol): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.cacheKey LIKE :pattern')
            ->setParameter('pattern', '%' . $symbol . '%')
            ->orderBy('v.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sauvegarde ou met à jour un cache de validation
     */
    public function upsert(ValidationCache $cache): void
    {
        $existing = $this->findByCacheKey($cache->getCacheKey());

        if ($existing) {
            $existing->setPayload($cache->getPayload());
            $existing->setExpiresAt($cache->getExpiresAt());
            $existing->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->getEntityManager()->flush();
        } else {
            $this->getEntityManager()->persist($cache);
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Vérifie si un cache existe et n'est pas expiré
     */
    public function isCacheValid(string $cacheKey): bool
    {
        $cache = $this->findByCacheKey($cacheKey);
        return $cache !== null && !$cache->isExpired();
    }

    /**
     * Invalide un cache
     */
    public function invalidateCache(string $cacheKey): void
    {
        $cache = $this->findByCacheKey($cacheKey);
        if ($cache) {
            $this->getEntityManager()->remove($cache);
            $this->getEntityManager()->flush();
        }
    }
}




