<?php

namespace App\Repository;

use App\Entity\UserExchangeAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserExchangeAccount>
 */
class UserExchangeAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserExchangeAccount::class);
    }

    public function findOneByUserAndExchange(string $userId, string $exchange): ?UserExchangeAccount
    {
        return $this->findOneBy([
            'userId' => $userId,
            'exchange' => strtolower($exchange),
        ]);
    }

    public function save(UserExchangeAccount $account, bool $flush = true): void
    {
        $this->getEntityManager()->persist($account);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(UserExchangeAccount $account, bool $flush = true): void
    {
        $this->getEntityManager()->remove($account);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

