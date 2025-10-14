<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MtfLock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MtfLock>
 */
class MtfLockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MtfLock::class);
    }

    /**
     * Acquiert un verrou avec timeout
     */
    public function acquireLock(
        string $lockKey,
        string $processId,
        int $timeoutSeconds = 300,
        ?string $metadata = null
    ): bool {
        $em = $this->getEntityManager();
        
        // Nettoyer les verrous expirés
        $this->cleanupExpiredLocks();
        
        // Vérifier si le verrou existe déjà
        $existingLock = $this->findOneBy(['lockKey' => $lockKey]);
        
        if ($existingLock) {
            // Si le verrou existe et n'est pas expiré, on ne peut pas l'acquérir
            if (!$existingLock->isExpired()) {
                return false;
            }
            
            // Si le verrou est expiré, on le supprime
            $em->remove($existingLock);
            $em->flush();
        }
        
        // Créer le nouveau verrou
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("+{$timeoutSeconds} seconds");
        $lock = new MtfLock($lockKey, $processId, $expiresAt, $metadata);
        
        try {
            $em->persist($lock);
            $em->flush();
            return true;
        } catch (\Exception $e) {
            // En cas de conflit (race condition), on ne peut pas acquérir le verrou
            return false;
        }
    }

    /**
     * Libère un verrou
     */
    public function releaseLock(string $lockKey, string $processId): bool
    {
        $lock = $this->findOneBy([
            'lockKey' => $lockKey,
            'processId' => $processId
        ]);
        
        if (!$lock) {
            return false;
        }
        
        $em = $this->getEntityManager();
        $em->remove($lock);
        $em->flush();
        
        return true;
    }

    /**
     * Vérifie si un verrou est actif
     */
    public function isLocked(string $lockKey): bool
    {
        $lock = $this->findOneBy(['lockKey' => $lockKey]);
        
        if (!$lock) {
            return false;
        }
        
        // Si le verrou est expiré, il n'est plus actif
        if ($lock->isExpired()) {
            $this->releaseLock($lockKey, $lock->getProcessId());
            return false;
        }
        
        return true;
    }

    /**
     * Obtient les informations d'un verrou actif
     */
    public function getLockInfo(string $lockKey): ?array
    {
        $lock = $this->findOneBy(['lockKey' => $lockKey]);
        
        if (!$lock || $lock->isExpired()) {
            return null;
        }
        
        return [
            'lock_key' => $lock->getLockKey(),
            'process_id' => $lock->getProcessId(),
            'acquired_at' => $lock->getAcquiredAt()->format('Y-m-d H:i:s'),
            'expires_at' => $lock->getExpiresAt()?->format('Y-m-d H:i:s'),
            'duration_seconds' => $lock->getDuration(),
            'metadata' => $lock->getMetadata()
        ];
    }

    /**
     * Nettoie les verrous expirés
     */
    public function cleanupExpiredLocks(): int
    {
        $em = $this->getEntityManager();
        
        $qb = $em->createQueryBuilder();
        $qb->delete(MtfLock::class, 'l')
           ->where('l.expiresAt IS NOT NULL')
           ->andWhere('l.expiresAt <= :now')
           ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        
        return $qb->getQuery()->execute();
    }

    /**
     * Force la libération d'un verrou (pour les cas d'urgence)
     */
    public function forceReleaseLock(string $lockKey): bool
    {
        $lock = $this->findOneBy(['lockKey' => $lockKey]);
        
        if (!$lock) {
            return false;
        }
        
        $em = $this->getEntityManager();
        $em->remove($lock);
        $em->flush();
        
        return true;
    }

    /**
     * Obtient tous les verrous actifs
     */
    public function getActiveLocks(): array
    {
        $this->cleanupExpiredLocks();
        
        $locks = $this->findAll();
        $activeLocks = [];
        
        foreach ($locks as $lock) {
            if (!$lock->isExpired()) {
                $activeLocks[] = [
                    'lock_key' => $lock->getLockKey(),
                    'process_id' => $lock->getProcessId(),
                    'acquired_at' => $lock->getAcquiredAt()->format('Y-m-d H:i:s'),
                    'expires_at' => $lock->getExpiresAt()?->format('Y-m-d H:i:s'),
                    'duration_seconds' => $lock->getDuration(),
                    'metadata' => $lock->getMetadata()
                ];
            }
        }
        
        return $activeLocks;
    }
}




