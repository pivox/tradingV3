<?php

declare(strict_types=1);

namespace App\Contract\Runtime;

use App\Contract\Runtime\Dto\LockInfoDto;

/**
 * Interface pour la gestion des verrous distribués
 * Inspiré de Symfony Contracts pour la gestion de la concurrence
 */
interface LockManagerInterface
{
    /**
     * Acquiert un verrou avec timeout
     */
    public function acquireLock(string $key, int $timeout = 30): bool;

    /**
     * Acquiert un verrou avec retry automatique
     */
    public function acquireLockWithRetry(
        string $key, 
        int $timeout = 30,
        int $maxRetries = 3,
        int $retryDelay = 100
    ): bool;

    /**
     * Libère un verrou
     */
    public function releaseLock(string $key): bool;

    /**
     * Vérifie si un verrou existe
     */
    public function isLocked(string $key): bool;

    /**
     * Récupère les informations d'un verrou
     */
    public function getLockInfo(string $key): ?LockInfoDto;

    /**
     * Force la libération d'un verrou (utilisé avec précaution)
     */
    public function forceReleaseLock(string $key): bool;

    /**
     * Récupère tous les verrous actifs
     */
    public function getAllLocks(): array;

    /**
     * Nettoie les verrous expirés
     */
    public function cleanupExpiredLocks(): int;
}
