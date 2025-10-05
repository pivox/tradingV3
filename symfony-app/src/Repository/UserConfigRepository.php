<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserConfig>
 */
class UserConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserConfig::class);
    }

    /**
     * Récupère la configuration par clé, ou retourne la configuration par défaut
     */
    public function getByKey(string $key = 'default'): UserConfig
    {
        $config = $this->findOneBy(['configKey' => $key]);

        if ($config === null) {
            // Si la config n'existe pas, retourne une nouvelle instance avec les valeurs par défaut
            $config = new UserConfig();
            $config->setConfigKey($key);
        }

        return $config;
    }

    /**
     * Récupère ou crée la configuration par défaut
     */
    public function getOrCreateDefault(): UserConfig
    {
        $config = $this->findOneBy(['configKey' => 'default']);

        if ($config === null) {
            $config = new UserConfig();
            $config->setConfigKey('default');
            $this->getEntityManager()->persist($config);
            $this->getEntityManager()->flush();
        }

        return $config;
    }

    /**
     * Sauvegarde ou met à jour une configuration
     */
    public function save(UserConfig $config, bool $flush = true): void
    {
        $this->getEntityManager()->persist($config);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
