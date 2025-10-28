<?php

declare(strict_types=1);

namespace App\Contract\Runtime;

use App\Contract\Runtime\Dto\SwitchStateDto;

/**
 * Interface pour la gestion des commutateurs de fonctionnalités
 * Inspiré de Symfony Contracts pour le contrôle d'exécution
 */
interface FeatureSwitchInterface
{
    /**
     * Active un commutateur
     */
    public function enable(string $switchName, ?string $reason = null): void;

    /**
     * Désactive un commutateur
     */
    public function disable(string $switchName, ?string $reason = null): void;

    /**
     * Vérifie si un commutateur est activé
     */
    public function isEnabled(string $switchName): bool;

    /**
     * Vérifie si un commutateur est désactivé
     */
    public function isDisabled(string $switchName): bool;

    /**
     * Bascule l'état d'un commutateur
     */
    public function toggle(string $switchName, ?string $reason = null): bool;

    /**
     * Définit l'état par défaut d'un commutateur
     */
    public function setDefaultState(string $switchName, bool $state): void;

    /**
     * Réinitialise un commutateur à son état par défaut
     */
    public function reset(string $switchName): void;

    /**
     * Réinitialise tous les commutateurs
     */
    public function resetAll(): void;

    /**
     * Récupère l'état actuel d'un commutateur
     */
    public function getState(string $switchName): ?bool;

    /**
     * Récupère tous les commutateurs et leurs états
     */
    public function getAllSwitches(): array;

    /**
     * Exécute une fonction si le commutateur est activé
     */
    public function executeIfEnabled(string $switchName, callable $callback, mixed $default = null): mixed;

    /**
     * Exécute une fonction si le commutateur est désactivé
     */
    public function executeIfDisabled(string $switchName, callable $callback, mixed $default = null): mixed;

    /**
     * Récupère les statistiques des commutateurs
     */
    public function getStats(): array;
}
