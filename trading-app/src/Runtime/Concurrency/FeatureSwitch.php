<?php

declare(strict_types=1);

namespace App\Runtime\Concurrency;

use App\Contract\Runtime\FeatureSwitchInterface;
use App\Contract\Runtime\Dto\SwitchStateDto;
use App\Runtime\Concurrency\Dto\SwitchConfigDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Gestionnaire de commutateurs pour contrôler l'exécution des processus
 * Permet d'activer/désactiver des fonctionnalités en runtime
 */
#[AsAlias(id: FeatureSwitchInterface::class)]
class FeatureSwitch implements FeatureSwitchInterface
{
    private array $switches = [];
    private array $defaultStates = [];

    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * Active un commutateur
     */
    public function enable(string $switchName, ?string $reason = null): void
    {
        $this->switches[$switchName] = true;
        
        $this->logger->info("Commutateur activé", [
            'switch' => $switchName,
            'reason' => $reason
        ]);
    }

    /**
     * Désactive un commutateur
     */
    public function disable(string $switchName, ?string $reason = null): void
    {
        $this->switches[$switchName] = false;
        
        $this->logger->info("Commutateur désactivé", [
            'switch' => $switchName,
            'reason' => $reason
        ]);
    }

    /**
     * Vérifie si un commutateur est activé
     */
    public function isEnabled(string $switchName): bool
    {
        return $this->switches[$switchName] ?? $this->defaultStates[$switchName] ?? false;
    }

    /**
     * Vérifie si un commutateur est désactivé
     */
    public function isDisabled(string $switchName): bool
    {
        return !$this->isEnabled($switchName);
    }

    /**
     * Bascule l'état d'un commutateur
     */
    public function toggle(string $switchName, ?string $reason = null): bool
    {
        $newState = !$this->isEnabled($switchName);
        
        if ($newState) {
            $this->enable($switchName, $reason);
        } else {
            $this->disable($switchName, $reason);
        }

        return $newState;
    }

    /**
     * Définit l'état par défaut d'un commutateur
     */
    public function setDefaultState(string $switchName, bool $state): void
    {
        $this->defaultStates[$switchName] = $state;
    }

    /**
     * Réinitialise un commutateur à son état par défaut
     */
    public function reset(string $switchName): void
    {
        unset($this->switches[$switchName]);
        
        $this->logger->info("Commutateur réinitialisé", [
            'switch' => $switchName,
            'default_state' => $this->defaultStates[$switchName] ?? false
        ]);
    }

    /**
     * Réinitialise tous les commutateurs
     */
    public function resetAll(): void
    {
        $this->switches = [];
        
        $this->logger->info("Tous les commutateurs ont été réinitialisés");
    }

    /**
     * Récupère l'état actuel d'un commutateur
     */
    public function getState(string $switchName): ?bool
    {
        return $this->switches[$switchName] ?? null;
    }

    /**
     * Récupère tous les commutateurs et leurs états
     */
    public function getAllSwitches(): array
    {
        $result = [];
        
        foreach ($this->switches as $name => $state) {
            $result[$name] = [
                'enabled' => $state,
                'is_default' => false
            ];
        }

        foreach ($this->defaultStates as $name => $state) {
            if (!isset($result[$name])) {
                $result[$name] = [
                    'enabled' => $state,
                    'is_default' => true
                ];
            }
        }

        return $result;
    }

    /**
     * Exécute une fonction si le commutateur est activé
     */
    public function executeIfEnabled(string $switchName, callable $callback, mixed $default = null): mixed
    {
        if ($this->isEnabled($switchName)) {
            return $callback();
        }

        return $default;
    }

    /**
     * Exécute une fonction si le commutateur est désactivé
     */
    public function executeIfDisabled(string $switchName, callable $callback, mixed $default = null): mixed
    {
        if ($this->isDisabled($switchName)) {
            return $callback();
        }

        return $default;
    }

    /**
     * Récupère les statistiques des commutateurs
     */
    public function getStats(): array
    {
        $total = count($this->switches) + count($this->defaultStates);
        $enabled = 0;
        $disabled = 0;

        foreach ($this->getAllSwitches() as $switch) {
            if ($switch['enabled']) {
                $enabled++;
            } else {
                $disabled++;
            }
        }

        return [
            'total_switches' => $total,
            'enabled' => $enabled,
            'disabled' => $disabled,
            'switches' => $this->getAllSwitches()
        ];
    }
}
