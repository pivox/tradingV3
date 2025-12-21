<?php

declare(strict_types=1);

namespace App\Config;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Provider pour charger les configs de contrats MTF selon le profil
 * Met en cache les configs pour éviter de recharger à chaque symbole
 * 
 * Pattern de résolution :
 * - Si profil fourni : essaie mtf_contracts.{profile}.yaml
 * - Si fichier spécifique n'existe pas ou profil non fourni : fallback sur mtf_contracts.yaml
 */
final class MtfContractsConfigProvider
{
    /** @var array<string, MtfContractsConfig> Cache des configs par profil */
    private array $configCache = [];

    private readonly string $configDir;

    public function __construct(
        ParameterBagInterface $parameterBag,
        private readonly LoggerInterface $logger
    ) {
        $this->configDir = $parameterBag->get('kernel.project_dir') . '/config/app';
    }

    /**
     * Obtient une config pour un profil donné (avec cache et fallback)
     * 
     * @param string|null $profile Nom du profil (ex: 'scalper', 'regular', 'scalper_micro')
     * @return MtfContractsConfig
     * @throws \RuntimeException Si aucun fichier de config n'est trouvé (ni spécifique ni fallback)
     */
    public function getConfigForProfile(?string $profile = null): MtfContractsConfig
    {
        // Clé de cache : utilise 'default' si profil est null
        $cacheKey = $profile ?? 'default';

        // Vérifier le cache
        if (isset($this->configCache[$cacheKey])) {
            return $this->configCache[$cacheKey];
        }

        // Résoudre le chemin avec fallback
        $path = $this->resolveConfigPath($profile);

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf(
                'Configuration file not found for profile "%s": %s (fallback also not found)',
                $profile ?? 'default',
                $path
            ));
        }

        // Créer et mettre en cache
        $config = new MtfContractsConfig($path);
        $this->configCache[$cacheKey] = $config;
        $version = null;
        try {
            $version = $config->all()['version'] ?? null;
        } catch (\Throwable) {
            $version = null;
        }
        $this->logger->info('[MTF_CONTRACTS] Config loaded', [
            'profile' => $profile ?? 'default',
            'path' => $path,
            'version' => $version,
        ]);

        return $config;
    }

    /**
     * Résout le chemin du fichier de config avec fallback
     * 
     * @param string|null $profile Nom du profil
     * @return string Chemin absolu du fichier de config à utiliser
     */
    private function resolveConfigPath(?string $profile): string
    {
        // Fichier de fallback par défaut
        $fallbackPath = $this->configDir . '/mtf_contracts.yaml';

        // Si aucun profil fourni, utiliser directement le fallback
        if ($profile === null || $profile === '') {
            return $fallbackPath;
        }

        // Essayer le fichier spécifique au profil
        $profilePath = $this->configDir . '/mtf_contracts.' . $profile . '.yaml';

        // Si le fichier spécifique existe, l'utiliser
        if (is_file($profilePath)) {
            return $profilePath;
        }

        // Sinon, utiliser le fallback
        return $fallbackPath;
    }

    /**
     * Vide le cache (utile pour les tests ou le rechargement)
     */
    public function clearCache(): void
    {
        $this->configCache = [];
    }
}
