<?php

declare(strict_types=1);

namespace App\Config;

use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Mode\ModeContractValidator;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Provider pour charger les configs TradeEntry selon le mode
 * Met en cache les configs pour éviter de recharger à chaque symbole
 */
final class TradeEntryConfigProvider
{
    /** @var array<string, TradeEntryConfig> Cache des configs par mode */
    private array $configCache = [];

    /** @var array<int, array{name: string, enabled: bool, priority: int}> Liste des modes activés triés par priority */
    private array $enabledModes = [];

    private readonly string $configDir;

    public function __construct(
        ParameterBagInterface $parameterBag
    ) {
        $this->configDir = $parameterBag->get('kernel.project_dir') . '/config/app';
        
        // Charger les modes depuis les paramètres
        $modes = $parameterBag->get('mode') ?? [];
        $this->enabledModes = $this->loadEnabledModes($modes);
    }

    /**
     * Charge les modes activés triés par priority
     * Le format YAML [name: 'x', enabled: true, priority: 1] est parsé comme:
     * [[['name' => 'x']], [['enabled' => true]], [['priority' => 1]]]
     * @param array<int, mixed> $modes
     * @return array<int, array{name: string, enabled: bool, priority: int}>
     */
    private function loadEnabledModes(array $modes): array
    {
        $enabled = [];
        foreach ($modes as $mode) {
            if (!is_array($mode) || count($mode) < 3) {
                continue;
            }
            
            $name = 'unknown';
            $enabledFlag = false;
            $priority = 999;
            
            // Extraire name, enabled, priority depuis le format spécial Symfony
            foreach ($mode as $item) {
                if (is_array($item)) {
                    if (isset($item['name'])) {
                        $name = is_string($item['name']) ? $item['name'] : 'unknown';
                    } elseif (isset($item['enabled'])) {
                        $enabledFlag = (bool)$item['enabled'];
                    } elseif (isset($item['priority'])) {
                        $priority = (int)$item['priority'];
                    }
                }
            }
            
            if ($enabledFlag) {
                $enabled[] = [
                    'name' => $name,
                    'enabled' => true,
                    'priority' => $priority,
                ];
            }
        }
        
        // Trier par priority (croissant)
        usort($enabled, fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        
        return $enabled;
    }

    /**
     * Retourne la liste des modes activés triés par priority
     * @return array<int, array{name: string, enabled: bool, priority: int}>
     */
    public function getEnabledModes(): array
    {
        return $this->enabledModes;
    }

    /**
     * Obtient un config pour un mode donné (avec cache)
     * @param string $modeName Nom du mode (ex: 'regular', 'scalping')
     * @return TradeEntryConfig
     * @throws \RuntimeException Si le fichier de config n'existe pas
     */
    public function getConfigForMode(string $modeName): TradeEntryConfig
    {
        if (in_array($modeName, ModeContractValidator::MODE_IDS, true)) {
            throw new TradingConfigException(sprintf('Modern mode "%s" must consume the canonical snapshot; legacy TradeEntry YAML is quarantined.', $modeName));
        }
        // Vérifier le cache
        if (isset($this->configCache[$modeName])) {
            return $this->configCache[$modeName];
        }

        // Déterminer le nom du fichier selon le mode
        $filename = $this->getConfigFilename($modeName);
        $path = $this->configDir . '/' . $filename;

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf(
                'Configuration file not found for mode "%s": %s',
                $modeName,
                $path
            ));
        }

        // Créer et mettre en cache
        $config = new TradeEntryConfig($path);
        $this->configCache[$modeName] = $config;

        return $config;
    }

    /**
     * Détermine le nom du fichier de config selon le mode
     * @param string $modeName
     * @return string
     */
    private function getConfigFilename(string $modeName): string
    {
        return sprintf('trade_entry.%s.yaml', $modeName);
    }

    /**
     * Vide le cache (utile pour les tests ou le rechargement)
     */
    public function clearCache(): void
    {
        $this->configCache = [];
    }
}
