<?php
namespace App\Repository;

use App\Entity\RuntimeGuard;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class RuntimeGuardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RuntimeGuard::class);
    }

    public function getOrCreate(string $guard = 'trading'): RuntimeGuard
    {
        $rg = $this->find($guard);
        if (!$rg) {
            $rg = new RuntimeGuard($guard);
            $em = $this->getEntityManager();   // ✅ pas de $_em
            $em->persist($rg);
            $em->flush();
        }
        return $rg;
    }

    public function isPaused(string $guard = 'trading'): bool
    {
        return $this->getOrCreate($guard)->isPaused();
    }

    public function pause(?string $reason = null, string $guard = 'trading'): void
    {
        $rg = $this->getOrCreate($guard);
        $rg->pause($reason);
        $this->getEntityManager()->flush();   // ✅ pas de $_em
    }

    public function resume(string $guard = 'trading'): void
    {
        $rg = $this->getOrCreate($guard);
        $rg->resume();
        $this->getEntityManager()->flush();   // ✅ pas de $_em
    }
}
