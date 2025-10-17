<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MtfSwitch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MtfSwitch>
 */
class MtfSwitchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MtfSwitch::class);
    }

    public function isGlobalSwitchOn(): bool
    {
        $switch = $this->findOneBy(['switchKey' => 'GLOBAL']);
        return $switch?->isOn() ?? true; // Par défaut, le switch est ON
    }

    public function isSymbolSwitchOn(string $symbol): bool
    {
        $switch = $this->findOneBy(['switchKey' => "SYMBOL:{$symbol}"]);
        
        if (!$switch) {
            return true; // Par défaut, le switch est ON
        }
        
        // Si le switch est expiré, on le considère comme ON
        if ($switch->isExpired()) {
            return true;
        }
        
        return $switch->isOn();
    }

    public function isSymbolTimeframeSwitchOn(string $symbol, string $timeframe): bool
    {
        $switch = $this->findOneBy(['switchKey' => "SYMBOL_TF:{$symbol}:{$timeframe}"]);
        
        if (!$switch) {
            return true; // Par défaut, le switch est ON
        }
        
        // Si le switch est expiré, on le considère comme ON
        if ($switch->isExpired()) {
            return true;
        }
        
        return $switch->isOn();
    }

    public function canProcessSymbol(string $symbol): bool
    {
        return $this->isGlobalSwitchOn() && $this->isSymbolSwitchOn($symbol);
    }

    public function canProcessSymbolTimeframe(string $symbol, string $timeframe): bool
    {
        return $this->canProcessSymbol($symbol) && $this->isSymbolTimeframeSwitchOn($symbol, $timeframe);
    }

    public function getOrCreateGlobalSwitch(): MtfSwitch
    {
        $switch = $this->findOneBy(['switchKey' => 'GLOBAL']);
        if (!$switch) {
            $switch = MtfSwitch::createGlobalSwitch();
            $this->getEntityManager()->persist($switch);
            $this->getEntityManager()->flush();
        }
        return $switch;
    }

    public function getOrCreateSymbolSwitch(string $symbol): MtfSwitch
    {
        $switchKey = "SYMBOL:{$symbol}";
        $switch = $this->findOneBy(['switchKey' => $switchKey]);
        if (!$switch) {
            $switch = MtfSwitch::createSymbolSwitch($symbol);
            $this->getEntityManager()->persist($switch);
            $this->getEntityManager()->flush();
        }
        return $switch;
    }

    public function getOrCreateSymbolTimeframeSwitch(string $symbol, string $timeframe): MtfSwitch
    {
        $switchKey = "SYMBOL_TF:{$symbol}:{$timeframe}";
        $switch = $this->findOneBy(['switchKey' => $switchKey]);
        if (!$switch) {
            $switch = MtfSwitch::createSymbolTimeframeSwitch($symbol, $timeframe);
            $this->getEntityManager()->persist($switch);
            $this->getEntityManager()->flush();
        }
        return $switch;
    }

    public function turnOffGlobal(): void
    {
        $switch = $this->getOrCreateGlobalSwitch();
        $switch->turnOff();
        $this->getEntityManager()->flush();
    }

    public function turnOnGlobal(): void
    {
        $switch = $this->getOrCreateGlobalSwitch();
        $switch->turnOn();
        $this->getEntityManager()->flush();
    }

    public function turnOffSymbol(string $symbol): void
    {
        $switch = $this->getOrCreateSymbolSwitch($symbol);
        $switch->turnOff();
        $this->getEntityManager()->flush();
    }

    public function turnOnSymbol(string $symbol): void
    {
        $switch = $this->getOrCreateSymbolSwitch($symbol);
        $switch->turnOn();
        $this->getEntityManager()->flush();
    }

    public function turnOffSymbolTimeframe(string $symbol, string $timeframe): void
    {
        $switch = $this->getOrCreateSymbolTimeframeSwitch($symbol, $timeframe);
        $switch->turnOff();
        $this->getEntityManager()->flush();
    }

    public function turnOnSymbolTimeframe(string $symbol, string $timeframe): void
    {
        $switch = $this->getOrCreateSymbolTimeframeSwitch($symbol, $timeframe);
        $switch->turnOn();
        $this->getEntityManager()->flush();
    }

    /**
     * Désactive un symbole pour une durée de 4 heures
     */
    public function turnOffSymbolFor4Hours(string $symbol): void
    {
        $switch = $this->getOrCreateSymbolSwitch($symbol);
        $switch->turnOff();
        $switch->setExpiresAt(new \DateTimeImmutable('+4 hours', new \DateTimeZone('UTC')));
        $switch->setDescription("Symbole désactivé temporairement pour 4h - " . date('Y-m-d H:i:s'));
        $this->getEntityManager()->flush();
    }

    /**
     * Désactive un symbole pour une durée personnalisée
     */
    public function turnOffSymbolForDuration(string $symbol, string $duration): void
    {
        $switch = $this->getOrCreateSymbolSwitch($symbol);
        $switch->turnOff();
        $convertedDuration = $this->convertDurationForPhp($duration);
        $switch->setExpiresAt(new \DateTimeImmutable("+{$convertedDuration}", new \DateTimeZone('UTC')));
        $switch->setDescription("Symbole désactivé temporairement pour {$duration} - " . date('Y-m-d H:i:s'));
        $this->getEntityManager()->flush();
    }

    /**
     * Convertit les formats de durée non supportés par PHP en formats supportés
     */
    private function convertDurationForPhp(string $duration): string
    {
        // Convertir les formats non supportés par PHP en formats supportés
        if (preg_match('/^(\d+)m$/', $duration, $matches)) {
            $minutes = (int) $matches[1];
            if ($minutes >= 60) {
                $hours = intval($minutes / 60);
                $remainingMinutes = $minutes % 60;
                if ($remainingMinutes > 0) {
                    return "{$hours}h {$remainingMinutes}m";
                } else {
                    return "{$hours}h";
                }
            }
            return $duration; // Moins de 60 minutes, PHP peut le gérer
        }

        return $duration;
    }

    /**
     * Nettoie les switches expirés (les remet à ON et supprime la date d'expiration)
     */
    public function cleanupExpiredSwitches(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        
        $qb = $this->createQueryBuilder('s');
        $expiredSwitches = $qb
            ->where('s.expiresAt IS NOT NULL')
            ->andWhere('s.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($expiredSwitches as $switch) {
            $switch->turnOn();
            $switch->setExpiresAt(null);
            $switch->setDescription(null);
            $count++;
        }

        if ($count > 0) {
            $this->getEntityManager()->flush();
        }

        return $count;
    }

    /**
     * @return MtfSwitch[]
     */
    public function findAllActiveSwitches(): array
    {
        return $this->findBy(['isOn' => true]);
    }

    /**
     * @return MtfSwitch[]
     */
    public function findAllInactiveSwitches(): array
    {
        return $this->findBy(['isOn' => false]);
    }

    /**
     * @return MtfSwitch[]
     */
    public function findSymbolSwitches(string $symbol): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.switchKey LIKE :pattern')
            ->setParameter('pattern', "SYMBOL:{$symbol}%")
            ->getQuery()
            ->getResult();
    }
}



