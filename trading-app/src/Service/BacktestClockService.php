<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Service utilitaire pour gérer l'heure fixe lors du backtesting
 */
final class BacktestClockService
{
    private ?\DateTimeImmutable $fixedTime = null;
    private ClockInterface $clock;

    public function __construct(ClockInterface $clock)
    {
        $this->clock = $clock;
    }

    /**
     * Définit une heure fixe pour le backtesting
     */
    public function setFixedTime(\DateTimeImmutable $fixedTime): void
    {
        $this->fixedTime = $fixedTime;
    }

    /**
     * Retire l'heure fixe (retour au temps réel)
     */
    public function clearFixedTime(): void
    {
        $this->fixedTime = null;
    }

    /**
     * Obtient l'heure actuelle (fixe si définie, sinon temps réel)
     */
    public function now(): \DateTimeImmutable
    {
        return $this->fixedTime ?? $this->clock->now();
    }

    /**
     * Vérifie si une heure fixe est définie
     */
    public function isFixedTimeEnabled(): bool
    {
        return $this->fixedTime !== null;
    }

    /**
     * Obtient l'heure fixe actuelle (null si pas définie)
     */
    public function getFixedTime(): ?\DateTimeImmutable
    {
        return $this->fixedTime;
    }

    /**
     * Avance l'heure fixe d'un certain nombre de secondes
     */
    public function advanceFixedTime(int $seconds): void
    {
        if ($this->fixedTime !== null) {
            $this->fixedTime = $this->fixedTime->modify("+{$seconds} seconds");
        }
    }

    /**
     * Avance l'heure fixe d'un certain nombre de minutes
     */
    public function advanceFixedTimeMinutes(int $minutes): void
    {
        if ($this->fixedTime !== null) {
            $this->fixedTime = $this->fixedTime->modify("+{$minutes} minutes");
        }
    }

    /**
     * Avance l'heure fixe d'un certain nombre d'heures
     */
    public function advanceFixedTimeHours(int $hours): void
    {
        if ($this->fixedTime !== null) {
            $this->fixedTime = $this->fixedTime->modify("+{$hours} hours");
        }
    }
}


