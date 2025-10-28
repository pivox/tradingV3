<?php

namespace App\Runtime\Cache;

/**
 * Cache de validation pour optimiser les performances
 * Stocke les résultats de validation pour éviter les recalculs
 */
class ValidationCache
{
    private array $cache = [];
    private int $maxSize = 1000;
    private int $ttl = 3600; // 1 heure

    public function __construct(int $maxSize = 1000, int $ttl = 3600)
    {
        $this->maxSize = $maxSize;
        $this->ttl = $ttl;
    }

    /**
     * Récupère une valeur du cache
     */
    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        $entry = $this->cache[$key];
        
        // Vérifier l'expiration
        if (time() > $entry['expires_at']) {
            unset($this->cache[$key]);
            return null;
        }

        return $entry['value'];
    }

    /**
     * Stocke une valeur dans le cache
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->ttl;
        
        // Nettoyer le cache si nécessaire
        if (count($this->cache) >= $this->maxSize) {
            $this->cleanup();
        }

        $this->cache[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttl,
            'created_at' => time()
        ];
    }

    /**
     * Vérifie si une clé existe dans le cache
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Supprime une clé du cache
     */
    public function delete(string $key): void
    {
        unset($this->cache[$key]);
    }

    /**
     * Vide le cache
     */
    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Nettoie les entrées expirées
     */
    private function cleanup(): void
    {
        $now = time();
        $this->cache = array_filter(
            $this->cache,
            fn($entry) => $entry['expires_at'] > $now
        );
    }

    /**
     * Retourne les statistiques du cache
     */
    public function getStats(): array
    {
        $now = time();
        $valid = 0;
        $expired = 0;

        foreach ($this->cache as $entry) {
            if ($entry['expires_at'] > $now) {
                $valid++;
            } else {
                $expired++;
            }
        }

        return [
            'total_entries' => count($this->cache),
            'valid_entries' => $valid,
            'expired_entries' => $expired,
            'max_size' => $this->maxSize,
            'ttl' => $this->ttl
        ];
    }
}
