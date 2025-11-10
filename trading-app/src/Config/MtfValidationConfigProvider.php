<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Provider pour charger les configs de validation MTF selon le mode
 * Met en cache les configs pour éviter de recharger à chaque symbole
 */
final class MtfValidationConfigProvider
{
    private const MINIMUM_MODE_PARTS = 3;

    /** @var array<string, MtfValidationConfig> Cache des configs par mode */
    private array $configCache = [];

    /** @var array<string, array{name: string, enabled: bool, priority: int}> Liste des modes activés triés par priority */
    private array $enabledModes = [];

    private readonly string $configDir;

    public function __construct(
        ParameterBagInterface $parameterBag
    ) {
        $this->configDir = $parameterBag->get('kernel.project_dir') . '/src/MtfValidator/config';
        
        // Charger les modes depuis les paramètres
        $modes = $parameterBag->get('mode') ?? [];
        $this->enabledModes = $this->loadEnabledModes($modes);
    }

    /**
     * Charge les modes activés triés par priority
     * Le format YAML [name: 'x', enabled: true, priority: 1] est parsé comme:
     * [[['name' => 'x']], [['enabled' => true]], [['priority' => 1]]]
     * La constante MINIMUM_MODE_PARTS reflète ce format où les trois blocs sont attendus.
     * @param array<int, array> $modes
     * @return array<int, array{name: string, enabled: bool, priority: int}>
     */
    private function loadEnabledModes(array $modes): array
    {
        $enabled = [];
        foreach ($modes as $mode) {
            if (!is_array($mode) || count($mode) < self::MINIMUM_MODE_PARTS) {
                trigger_error(
                    sprintf(
                        '[MtfValidationConfigProvider] Skipping invalid mode configuration: %s',
                        var_export($mode, true)
                    ),
                    E_USER_WARNING
                );

                continue;
            }
            
            $name = 'unknown';
            $enabledFlag = false;
            $priority = 999;
            
            // Extraire name, enabled, priority depuis le format spécial Symfony
            foreach ($mode as $item) {
                if (is_array($item)) {
                    if (isset($item['name'])) {
                        $name = $item['name'];
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
        usort($enabled, fn($a, $b) => ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999));
        
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
     * @return MtfValidationConfig
     * @throws \RuntimeException Si le fichier de config n'existe pas
     */
    public function getConfigForMode(string $modeName): MtfValidationConfig
    {
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
        $config = new MtfValidationConfig($path);
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
        // Mapping des modes vers les noms de fichiers
        $mapping = [
            'regular' => 'validations.regular.yaml',
            'scalping' => 'validations.scalper.yaml',
        ];

        // Si le mapping existe, l'utiliser
        if (isset($mapping[$modeName])) {
            $mappedFile = $mapping[$modeName];
            $mappedPath = $this->configDir . '/' . $mappedFile;
            
            // Si le fichier mappé existe, l'utiliser
            if (is_file($mappedPath)) {
                return $mappedFile;
            }
        }

        // Fallback : pour 'regular', essayer validations.yaml
        if ($modeName === 'regular') {
            $fallbackPath = $this->configDir . '/validations.yaml';
            if (is_file($fallbackPath)) {
                return 'validations.yaml';
            }
        }

        // Sinon, utiliser le pattern validations.{mode}.yaml
        return sprintf('validations.%s.yaml', $modeName);
    }

    /**
     * Vide le cache (utile pour les tests ou le rechargement)
     */
    public function clearCache(): void
    {
        $this->configCache = [];
    }
}

